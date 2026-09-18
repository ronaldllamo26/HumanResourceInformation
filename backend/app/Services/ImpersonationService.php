<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing an administrator in as somebody else, for troubleshooting.
 *
 * "It works for me" is the answer a support request most often gets, and the
 * reason is usually scope: this system narrows almost every screen by role
 * and by who reports to whom, so a bug an employee sees on their own payslip
 * is frequently invisible to the person asked to fix it. Reproducing it any
 * other way means asking for their password, which is the one thing nobody
 * should ever be asked for.
 *
 * So the ability exists — and it is built on the assumption that it *will* be
 * misused eventually, because an account takeover with a button is what it is
 * if nothing constrains it. Four things constrain it:
 *
 * 1. **Only a super administrator may start one**, and never against another
 *    super administrator. A peer is the one target where impersonation buys
 *    nothing for support and everything for hiding an action behind a
 *    colleague's name.
 * 2. **Every row written during one names the administrator**
 *    (`audit_logs.impersonated_by`), so the trail an auditor reads says "X
 *    acting as Y" rather than filing an administrator's action under the
 *    employee it was done to.
 * 3. **The credential routes are closed while it runs** (see
 *    `BlockWhileImpersonating`). Reading somebody's screen is support;
 *    changing the password they sign in with is takeover, and the difference
 *    has to be enforced rather than trusted.
 * 4. **The session says so on every screen** while it lasts, because an
 *    administrator who has forgotten they are impersonating is one who will
 *    take an action believing it is their own.
 *
 * The original administrator's id is kept in the session rather than a table:
 * an impersonation lasts exactly as long as the browser session it was
 * started in, and a row would outlive that and have to be reconciled.
 */
class ImpersonationService
{
    /** Where the real administrator's id waits while the session is somebody else. */
    public const SESSION_KEY = 'impersonation.administrator_id';

    /** So a screen can say how long this has been running. */
    public const STARTED_AT_KEY = 'impersonation.started_at';

    public const EVENT_STARTED = 'impersonation_started';

    public const EVENT_STOPPED = 'impersonation_stopped';

    /**
     * Whether this administrator may take over that account.
     *
     * The policy decides *who* may impersonate at all; this decides *whom*
     * they may impersonate, which is a fact about the pair rather than about
     * the actor. Kept here rather than in the policy because the reasons are
     * about this feature and would otherwise be spread across both.
     *
     * @return string|null the reason it is refused, or null when allowed
     */
    public function refusalReason(User $administrator, User $target): ?string
    {
        if ($administrator->is($target)) {
            return 'You are already signed in as this account.';
        }

        /*
         * A peer holds everything you hold, so there is nothing about their
         * screen you cannot already see — and an action taken as them is an
         * action the trail attributes to another administrator. That is the
         * one shape of this feature with no support value and real cover for
         * misuse.
         */
        if ($target->isSuperAdmin()) {
            return 'A super administrator cannot be impersonated.';
        }

        /*
         * `EnsureAccountIsActive` would sign the session straight back out on
         * its very next request, so this would look broken rather than
         * refused. Reactivating the account is the honest way in.
         */
        if (! $target->is_active) {
            return 'This account is deactivated. Reactivate it first.';
        }

        return null;
    }

    /**
     * Becomes the target account, keeping the administrator's id in session.
     *
     * Audited *before* the switch, while `Auth::id()` is still the
     * administrator: written after, the row recording the start of an
     * impersonation would itself be attributed to the person being
     * impersonated, which is the confusion this whole class exists to avoid.
     */
    public function start(Request $request, User $administrator, User $target): void
    {
        AuditLog::create([
            'user_id' => $administrator->id,
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'event' => self::EVENT_STARTED,
            'new_values' => [
                'target_username' => $target->username,
                'target_role' => $target->role,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        /*
         * The id is put in the session *before* the login, because
         * `Auth::login()` on a different user regenerates nothing by itself
         * but the guard's user is read by everything downstream — and a
         * half-switched session with no administrator recorded in it is one
         * nobody can get back out of.
         */
        $request->session()->put(self::SESSION_KEY, $administrator->id);
        $request->session()->put(self::STARTED_AT_KEY, now()->toIso8601String());

        Auth::login($target);
    }

    /**
     * Hands the session back to the administrator who started it.
     *
     * Returns false when the session is not impersonating or the
     * administrator's account has since been deleted or deactivated — in
     * which case the safe answer is a signed-out session rather than one left
     * holding somebody else's identity.
     */
    public function stop(Request $request): bool
    {
        $administratorId = $request->session()->pull(self::SESSION_KEY);
        $request->session()->forget(self::STARTED_AT_KEY);

        if ($administratorId === null) {
            return false;
        }

        $administrator = User::find($administratorId);
        $impersonated = Auth::user();

        if (! $administrator || ! $administrator->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return false;
        }

        if ($impersonated) {
            AuditLog::create([
                'user_id' => $administrator->id,
                'auditable_type' => User::class,
                'auditable_id' => $impersonated->id,
                'event' => self::EVENT_STOPPED,
                'new_values' => ['target_username' => $impersonated->username],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        Auth::login($administrator);

        return true;
    }

    /** The administrator behind the current session, if it is an impersonation. */
    public function administrator(): ?User
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : User::find($id);
    }

    public function administratorId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : (int) $id;
    }

    public function isImpersonating(): bool
    {
        return session(self::SESSION_KEY) !== null;
    }
}
