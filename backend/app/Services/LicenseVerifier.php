<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What can honestly be said about a driver's licence from the record alone.
 *
 * **This does not talk to LTO, and it is important that it does not pretend
 * to.** The agency publishes no endpoint an employer can call to ask whether
 * somebody else's licence is genuine — LTMS is a citizen-facing portal where
 * a holder signs in to manage their own. The commercial "LTO verification
 * APIs" that advertise otherwise are private wrappers whose data source LTO
 * does not vouch for.
 *
 * So the claim is split in two, and each half is labelled as what it is:
 *
 *  - **Structure**, checked here and automatically: does the number look like
 *    a licence number, does the agency code agree with it, are the DL codes
 *    real codes, does the expiry fall on the holder's birthday. This catches
 *    a typo, a transposition, and a card filled in from the wrong scheme. It
 *    cannot catch a well-made forgery, and it never claims to.
 *  - **Authenticity**, checked by a person on the LTMS portal, whose answer is
 *    recorded on the employee with their name and the date. That is the only
 *    honest source of "this licence is real" available.
 *
 * Database-free and config-driven, the same shape as `CredentialExpiryScanner`
 * and `AttendanceExceptionScanner`: everything it knows comes from
 * `config/licenses.php`, so a new DL code is a config edit.
 */
class LicenseVerifier
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    /**
     * Every structural finding against one employee's licence.
     *
     * A licence with nothing filed returns an empty list rather than a
     * complaint: whether a driver is *missing* a licence is `OnboardingChecker`'s
     * question, and saying it twice on two screens teaches people to ignore
     * both.
     *
     * @return array<int, array{severity: string, field: string, summary: string, detail: string}>
     */
    public function check(Employee $employee): array
    {
        if (blank($employee->drivers_license_number)) {
            return [];
        }

        return array_values(array_filter([
            $this->numberShape($employee),
            ...$this->codeList($employee, 'license_dl_codes', 'dl_codes', 'DL code'),
            ...$this->codeList($employee, 'license_conditions', 'conditions', 'Condition'),
            $this->expiryOnBirthday($employee),
        ]));
    }

    /**
     * Whether the automatic checks all passed.
     *
     * Deliberately not called "valid": passing means the card is internally
     * consistent, which is a much smaller claim than being genuine.
     */
    public function isStructurallySound(Employee $employee): bool
    {
        return collect($this->check($employee))
            ->where('severity', self::SEVERITY_ERROR)
            ->isEmpty();
    }

    /**
     * The state of the human check — the only thing that speaks to authenticity.
     *
     * `stale` rather than `expired`, because a verification does not lapse the
     * way a licence does. A licence can be suspended the day after somebody
     * looked at it, so the date says "nobody has checked this in a year", not
     * "this is now invalid".
     */
    public function verificationState(Employee $employee): array
    {
        $at = $employee->license_verified_at;

        if (! $at) {
            return ['state' => 'unverified', 'days' => null];
        }

        $days = (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay());
        $window = (int) config('licenses.verification_valid_days', 365);

        return [
            'state' => $days > $window ? 'stale' : 'verified',
            'days' => $days,
        ];
    }

    /** The DL codes this employee holds, as an array of real codes. */
    public function dlCodes(Employee $employee): array
    {
        return $this->split($employee->license_dl_codes);
    }

    /**
     * Conditions that restrict when or what somebody may drive.
     *
     * Read by Deployment Readiness: a driver limited to daylight cannot
     * lawfully take a night run, which is a scheduling fact rather than a
     * paperwork one, and the person assigning the run should see it before
     * they assign it.
     *
     * @return array<int, string> Descriptions, in the agency's own wording.
     */
    public function operationalConditions(Employee $employee): array
    {
        $defined = config('licenses.conditions', []);
        $operational = config('licenses.operational_conditions', []);

        return collect($this->split($employee->license_conditions))
            ->filter(fn (string $code) => in_array($code, $operational, true))
            ->map(fn (string $code) => $defined[$code] ?? "Condition {$code}")
            ->values()
            ->all();
    }

    /*
     * -----------------------------------------------------------------
     * The individual checks
     * -----------------------------------------------------------------
     */

    private function numberShape(Employee $employee): ?array
    {
        $pattern = config('licenses.number_pattern');

        if (preg_match($pattern, trim($employee->drivers_license_number))) {
            return null;
        }

        return [
            'severity' => self::SEVERITY_ERROR,
            'field' => 'drivers_license_number',
            'summary' => 'Licence number is not the shape LTO issues',
            'detail' => 'An LTO licence reads as an agency code, a two-digit year, '
                .'and a six-digit serial — N02-24-001292.',
        ];
    }

    /**
     * Every code filed against the card's own list.
     *
     * @return array<int, array>
     */
    private function codeList(Employee $employee, string $field, string $configKey, string $noun): array
    {
        $defined = array_map('strval', array_keys(config("licenses.{$configKey}", [])));

        return collect($this->split($employee->{$field}))
            ->reject(fn (string $code) => in_array($code, $defined, true))
            ->map(fn (string $code) => [
                'severity' => self::SEVERITY_ERROR,
                'field' => $field,
                'summary' => "{$noun} \"{$code}\" is not one LTO issues",
                'detail' => 'The card carries '.implode(', ', $defined).'.',
            ])
            ->values()
            ->all();
    }

    /**
     * A licence expires on the holder's birthday.
     *
     * A mismatch means one of the two dates was keyed wrong, and it is worth
     * saying which pair disagrees rather than which one is wrong — the record
     * cannot tell.
     *
     * A warning, never an error: renewals around a birthday and the occasional
     * extension granted by memorandum are real enough that refusing the entry
     * would reject correct records to catch wrong ones.
     */
    private function expiryOnBirthday(Employee $employee): ?array
    {
        if (! config('licenses.expires_on_birthday', true)) {
            return null;
        }

        $expiry = $employee->license_expiry;
        $birth = $employee->birth_date;

        if (! $expiry || ! $birth) {
            return null;
        }

        $expiry = Carbon::parse($expiry);
        $birth = Carbon::parse($birth);

        if ($expiry->month === $birth->month && $expiry->day === $birth->day) {
            return null;
        }

        return [
            'severity' => self::SEVERITY_WARNING,
            'field' => 'license_expiry',
            'summary' => 'Licence does not expire on this employee\'s birthday',
            'detail' => 'An LTO licence expires on the holder\'s birthday. Expiry is '
                .$expiry->format('j F').' and the date of birth is '.$birth->format('j F')
                .' — one of the two was keyed wrong.',
        ];
    }

    /** Splits a comma- or space-separated list into trimmed, upper-case codes. */
    private function split(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(preg_split('/[,\s]+/', Str::upper(trim($value))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
