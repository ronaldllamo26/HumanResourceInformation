<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\LoginOtp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * The sign-in code: issuing one, and deciding whether an answer is it.
 *
 * Every rule about the code lives here and nothing else knows how one is made
 * or checked — the middleware only asks whether this session has passed, and
 * the controller only forwards an answer. That split is what lets the whole of
 * it be tested without a browser, the same shape `AttendanceCalculator` and
 * the other rule engines take.
 *
 * **The code goes to the person's own inbox, not to their login.** The
 * username is `name@primepower.com` and nothing is ever mailed to it; the
 * address a code can actually reach is `users.otp_email`, connected by an
 * administrator on Users & Access. Having one *is* the enrolment — see the
 * migration for why there is no second flag beside it.
 *
 * **The code is never stored, only its hash.** A live six-digit code sitting
 * in plaintext would make that column a better target than the password hash
 * beside it: a password hash cannot be replayed and a plaintext code can. The
 * only place the code itself exists is the email, in transit.
 *
 * **An SMS channel is deliberately not here.** Every gateway reachable from
 * the Philippines is prepaid, so the channel has a running cost and a day it
 * silently stops working — and the addresses these codes go to are inboxes
 * people already have open. If SMS ever comes back it comes back *beside*
 * this, not instead of it.
 */
class OtpService
{
    /** Where a verified session is remembered. */
    public const SESSION_KEY = 'otp.verified_at';

    public const EVENT_SENT = 'otp_sent';

    public const EVENT_PASSED = 'otp_passed';

    public const EVENT_FAILED = 'otp_failed';

    /** Every event this service writes to the audit log. */
    public const EVENTS = [self::EVENT_SENT, self::EVENT_PASSED, self::EVENT_FAILED];

    /**
     * Whether this account has to answer a code.
     *
     * Two conditions, and both are deliberate. The switch is the way back in
     * when mail breaks (`config/otp.php`), and the address is the enrolment:
     * an account with nowhere to send a code cannot be held behind one
     * without locking it out of the system for good.
     */
    public function isRequiredFor(?User $user): bool
    {
        return $user !== null
            && (bool) config('otp.enabled', true)
            && (bool) ($user->otp_enabled ?? true)
            && filled($user->otp_email);
    }

    /**
     * Issues a fresh code and emails it.
     *
     * The attempt counter resets here rather than on a failed answer: a new
     * code is a new question, and carrying the old count over would let five
     * wrong guesses spread across two codes lock somebody out of a code they
     * had only just been sent.
     *
     * Returns false when the mail could not be handed to the mailer — the
     * screen says so rather than leaving somebody waiting for an email that
     * was never going to arrive. **A failed send does not let anybody
     * through**: a factor that switched itself off when mail broke would be a
     * factor anybody could switch off by breaking mail.
     */
    public function send(User $user): bool
    {
        $code = $this->generate();

        $user->forceFill([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => Carbon::now()->addSeconds($this->ttl()),
            'otp_sent_at' => Carbon::now(),
            'otp_attempts' => 0,
        ])->save();

        try {
            $user->notify(new LoginOtp($code, $this->ttl()));
        } catch (\Throwable $exception) {
            // The address and the reason, never the code.
            Log::warning('Sign-in code could not be sent.', [
                'user_id' => $user->id,
                'reason' => $exception->getMessage(),
            ]);

            $this->clear($user);

            return false;
        }

        $this->record($user, self::EVENT_SENT);

        return true;
    }

    /**
     * Where the code went, obscured for a screen shown before the factor is
     * cleared.
     *
     * It is the reader's own inbox, so saying which one is the point — but
     * printing it in full on a screen anybody with the password can reach
     * would hand over the address as well.
     */
    public function destinationFor(User $user): string
    {
        return $this->maskEmail((string) $user->otp_email);
    }

    /**
     * Whether another code may be sent yet.
     *
     * One number paces two different abuses: somebody hammering "resend" to
     * fill another person's inbox, and somebody requesting codes in bulk to
     * learn which addresses exist.
     */
    public function canResend(User $user): bool
    {
        return $this->secondsUntilResend($user) === 0;
    }

    public function secondsUntilResend(User $user): int
    {
        if ($user->otp_sent_at === null) {
            return 0;
        }

        $ready = $user->otp_sent_at->addSeconds((int) config('otp.resend_after_seconds', 45));

        return max(0, (int) Carbon::now()->diffInSeconds($ready, false));
    }

    /**
     * Checks an answer, and consumes the code either way.
     *
     * **A wrong answer costs an attempt and a right one clears the code**, so
     * a code cannot be replayed from a browser history entry or a second tab.
     * Past `max_attempts` the code is burned rather than the account locked:
     * locking would hand anybody who knows a username a way to keep its owner
     * out, which is a denial of service dressed as a security control.
     */
    public function verify(User $user, string $answer): bool
    {
        if (! $this->hasLiveCode($user)) {
            return false;
        }

        if ($user->otp_attempts >= $this->maxAttempts()) {
            $this->clear($user);

            return false;
        }

        // Counted before the comparison, so a request that dies mid-check
        // still costs the attempt rather than being free to retry.
        $user->forceFill(['otp_attempts' => $user->otp_attempts + 1])->save();

        if (! Hash::check($this->normalise($answer), (string) $user->otp_code_hash)) {
            // The last attempt takes the code with it, so the next screen
            // offers a fresh one rather than a field that cannot succeed.
            if ($user->otp_attempts >= $this->maxAttempts()) {
                $this->clear($user);
            }

            $this->record($user, self::EVENT_FAILED);

            return false;
        }

        $this->clear($user);

        /*
         * Answering a code sent to that address is the only evidence that the
         * address is really the person's and was typed without a slip — so
         * this, rather than the administrator's say-so, is what marks it
         * verified.
         */
        if ($user->otp_email_verified_at === null) {
            $user->forceFill(['otp_email_verified_at' => Carbon::now()])->save();
        }

        $this->record($user, self::EVENT_PASSED);

        return true;
    }

    /** Whether an unexpired code is outstanding. */
    public function hasLiveCode(User $user): bool
    {
        return $user->otp_code_hash !== null
            && $user->otp_expires_at !== null
            && $user->otp_expires_at->isFuture();
    }

    /** Whether a code was generated but has since expired. */
    public function isExpired(User $user): bool
    {
        return $user->otp_code_hash !== null
            && $user->otp_expires_at !== null
            && $user->otp_expires_at->isPast();
    }

    /** How many answers are left before this code is burned. */
    public function attemptsLeft(User $user): int
    {
        return max(0, $this->maxAttempts() - (int) $user->otp_attempts);
    }

    public function secondsUntilExpiry(User $user): int
    {
        return $this->hasLiveCode($user)
            ? max(0, (int) Carbon::now()->diffInSeconds($user->otp_expires_at, false))
            : 0;
    }

    /**
     * Forgets the outstanding code.
     *
     * Called on success, on exhaustion, and when the address is changed — a
     * code left behind after any of those is a credential nobody is expecting
     * to still work.
     */
    public function clear(User $user): void
    {
        $user->forceFill([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }

    /**
     * An audit row per code sent, passed and failed.
     *
     * Written here rather than in the controller because the service is what
     * knows which of the three happened, and because the resend path and the
     * middleware's first-request send would otherwise each need their own
     * copy. `AuditLog` signs every row in its `created` hook, so these are
     * tamper-evident like the sign-in rows they sit beside.
     */
    private function record(User $user, string $event): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'event' => $event,
            'old_values' => null,
            // The address, never the code — and masked, because this table is
            // read on a screen and exported to a CSV.
            'new_values' => ['username' => $user->username, 'sent_to' => $this->destinationFor($user)],
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /** `ju***@gmail.com` — enough to recognise, not enough to harvest. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $keep.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($keep)))
            .($domain === '' ? '' : '@'.$domain);
    }

    /**
     * A uniformly random code of the configured length.
     *
     * `random_int` rather than `rand`, because this is a credential: the
     * Mersenne Twister behind `rand()` is predictable from previous output,
     * which for a code guarding a payroll system is the whole attack.
     * Zero-padded, so a leading zero is not silently a five-digit code.
     */
    private function generate(): string
    {
        $length = max(4, (int) config('otp.length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** Spaces and dashes are what people paste; they are not a wrong answer. */
    private function normalise(string $answer): string
    {
        return preg_replace('/\D/', '', $answer) ?? '';
    }

    private function ttl(): int
    {
        return max(30, (int) config('otp.ttl_seconds', 120));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('otp.max_attempts', 5));
    }
}
