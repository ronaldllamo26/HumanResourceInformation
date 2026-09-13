<?php

namespace App\Http\Resources;

use App\Services\LicenseVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver, as the Fleet & Transportation system needs to see one.
 *
 * Deliberately not `EmployeeResource` with extra fields. Fleet does not want a
 * person record — it wants an answer to one question before a run is assigned:
 * **may this driver lawfully take this vehicle, on this shift, today?** Three
 * things decide it, and none of them is on the employee row by itself:
 *
 *  - the **DL codes** are the legal ceiling on the vehicle class,
 *  - the **conditions** rule out some runs (condition 4 is daylight only),
 *  - the **licence expiry** decides whether they may drive at all.
 *
 * Salary, bank details, and government numbers are absent, and that is not an
 * oversight: Fleet has no reason to hold them, and an integration that hands
 * over more than the consumer needs is the failure that gets noticed after a
 * breach rather than before one.
 */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $verifier = app(LicenseVerifier::class);
        $conditions = $verifier->operationalConditions($this->resource);

        return [
            'employee_id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'employment_status' => $this->employment_status,
            'status' => $this->status,

            // Where they are posted. A client's dispatcher only wants theirs.
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client?->name),
            'position' => $this->whenLoaded('position', fn () => $this->position?->title),

            'licence' => [
                'number' => $this->drivers_license_number,
                'expires_at' => $this->license_expiry?->toDateString(),

                /*
                 * Spelled out, not just coded. "C" means nothing to a
                 * dispatcher; "Goods over 3500 kgs GVW" is the rule they are
                 * actually applying, and sending only the code would make
                 * every consumer keep its own copy of the LTO table.
                 */
                'dl_codes' => collect($verifier->dlCodes($this->resource))
                    ->map(fn (string $code) => [
                        'code' => $code,
                        'label' => config("licenses.dl_codes.{$code}"),
                    ])
                    ->values(),

                'conditions' => collect(explode(',', (string) $this->license_conditions))
                    ->map(fn (string $code) => trim($code))
                    ->filter()
                    ->map(fn (string $code) => [
                        'code' => $code,
                        'label' => config("licenses.conditions.{$code}"),
                    ])
                    ->values(),

                /*
                 * The two answers Fleet is really asking for, computed here
                 * rather than left for each consumer to derive. A dispatcher
                 * deriving "expired" from a date is a dispatcher who will one
                 * day get the timezone wrong.
                 */
                'is_expired' => $this->license_expiry !== null
                    && $this->license_expiry->isPast(),

                'expires_within_days' => $this->license_expiry
                    ? (int) now()->startOfDay()->diffInDays($this->license_expiry, false)
                    : null,

                // Structure only. Never called "valid": passing means the card
                // is internally consistent, not that it is genuine.
                'structurally_sound' => $verifier->isStructurallySound($this->resource),

                // The human LTMS check — the only thing that speaks to
                // authenticity, and it is a date and a name, not a tick.
                'ltms_check' => $verifier->verificationState($this->resource)['state'],
                'ltms_checked_at' => $this->license_verified_at?->toDateString(),
            ],

            /*
             * What the licence forbids, in plain words, ready to be shown to
             * whoever is assigning the run. Empty means nothing restricts them
             * beyond their DL codes.
             */
            'operational_restrictions' => $conditions,

            /*
             * The single field a dispatch screen should key on. False means
             * assigning this driver would be unlawful, not merely untidy —
             * the same line `DeploymentReadinessChecker` draws, and the same
             * `credentials.blocking_types` config behind it.
             */
            'may_drive' => $this->status === 'active'
                && filled($this->drivers_license_number)
                && ! ($this->license_expiry?->isPast() ?? true),

            /*
             * How far ahead this system starts chasing a renewal, sent so a
             * consumer can colour its own screen on the same threshold rather
             * than inventing a second one. A licence wants weeks of lead time;
             * the number lives in `config/credentials.php`.
             */
            'warning_window_days' => (int) config(
                'credentials.warning_days.drivers_license',
                config('credentials.default_warning_days', 30),
            ),
        ];
    }
}
