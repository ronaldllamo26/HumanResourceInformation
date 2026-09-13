<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Writes authentication events into the same `audit_logs` table the Auditable
 * trait uses for model changes.
 *
 * The trait answers "who changed this record"; it cannot answer "who was in
 * the system last night, and who tried and failed" — the first question an
 * auditor asks of an HRIS holding salaries and government IDs. Both now live
 * in one table, so the Security screen's log is the whole story rather than
 * half of it.
 *
 * A password is never recorded. A *failed* attempt records the address that
 * was tried, because without it the entry says nothing: the point of the row
 * is to show which account was being guessed at.
 */
class RecordAuthenticationEvents
{
    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_FAILED = 'login_failed';

    public const EVENT_LOCKOUT = 'lockout';

    /** Every auth event this class records, for filtering the log by kind. */
    public const EVENTS = [
        self::EVENT_LOGIN,
        self::EVENT_LOGOUT,
        self::EVENT_FAILED,
        self::EVENT_LOCKOUT,
    ];

    /**
     * Registered from AppServiceProvider so the wiring is visible there.
     *
     * The methods below are named `record*` rather than `handle*` on purpose:
     * Laravel discovers listeners in `app/Listeners` by looking for methods
     * beginning with `handle` and reading their type hint. With that prefix
     * every event here would be registered twice — once by discovery, once by
     * this subscriber — and every sign-in would be written to the audit log
     * twice over. Renaming the prefix is what keeps the explicit registration
     * the only one.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'recordLogin']);
        $events->listen(Logout::class, [self::class, 'recordLogout']);
        $events->listen(Failed::class, [self::class, 'recordFailed']);
        $events->listen(Lockout::class, [self::class, 'recordLockout']);
    }

    public function recordLogin(Login $event): void
    {
        $this->write(self::EVENT_LOGIN, $event->user->getAuthIdentifier(), $event->user->getAuthIdentifier());
    }

    public function recordLogout(Logout $event): void
    {
        // A session that expired rather than being signed out has no user.
        if ($event->user === null) {
            return;
        }

        $this->write(self::EVENT_LOGOUT, $event->user->getAuthIdentifier(), $event->user->getAuthIdentifier());
    }

    public function recordFailed(Failed $event): void
    {
        // `user` is set when the address exists but the password was wrong,
        // and null when the address is unknown. Both are worth keeping, and
        // they are different findings: one is a user who mistyped, the other
        // is someone guessing at addresses.
        $this->write(
            self::EVENT_FAILED,
            actorId: null,
            subjectId: $event->user?->getAuthIdentifier(),
            attempted: $this->attemptedEmail($event->credentials),
        );
    }

    public function recordLockout(Lockout $event): void
    {
        $this->write(
            self::EVENT_LOCKOUT,
            actorId: null,
            subjectId: null,
            attempted: $this->attemptedEmail($event->request->only(['email', 'username'])),
        );
    }

    /**
     * What was typed into the sign-in box.
     *
     * The web form sends an `email`; read both email and username so a failed
     * attempt is recorded against whatever was entered.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function attemptedEmail(array $credentials): ?string
    {
        $typed = $credentials['email'] ?? $credentials['username'] ?? null;

        return is_string($typed) ? mb_substr($typed, 0, 255) : null;
    }

    private function write(string $event, ?int $actorId, ?int $subjectId, ?string $attempted = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => $actorId,
            'auditable_type' => User::class,
            'auditable_id' => $subjectId,
            'event' => $event,
            'old_values' => null,
            // Only ever the address that was typed — never the password, and
            // never the rest of the credential array.
            'new_values' => $attempted === null ? null : [
                'email' => $attempted,
                'username' => $attempted,
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
