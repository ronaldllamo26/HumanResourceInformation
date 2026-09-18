<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an impersonated session out of the things that are not support.
 *
 * Impersonation exists to see what somebody else sees. The line it must not
 * cross is **changing what they sign in with**: a session that can set the
 * password, move the second-factor inbox, or mint an API token is not a
 * support tool, it is an account takeover that happens to be logged. And the
 * takeover would be quiet in the worst way — the employee's password simply
 * stops working, with a trail saying they changed it themselves.
 *
 * So the refusal is enforced here rather than left to whoever remembers.
 * Everything else is deliberately still allowed: the whole value of the
 * feature is reproducing a real screen, and an impersonation that could only
 * read would not reproduce the bug reports that prompted it.
 *
 * **It is a 403 rather than a redirect**, because this is not a hold the
 * person can clear by doing something — there is no next step, and a
 * redirect to a screen that then also refuses is a loop. The message names
 * the way out: stop impersonating and do it as yourself.
 */
class BlockWhileImpersonating
{
    /**
     * Named routes an impersonated session may not reach.
     *
     * Route names rather than paths, so a moved URL cannot silently reopen
     * one of them. Each is a credential rather than a record:
     *
     *  · the password on the Security screen, and Fortify's own two
     *    endpoints, which is the same act by a different door
     *  · the account's profile and role on Users & Access — the username is
     *    half of the credential, and the role is the authority behind it
     *  · a password reset for anybody, which hands out a new credential
     *  · API tokens, which outlive the session that minted them and are not
     *    covered by anything else here
     *  · the account-request queue, whose whole purpose is that a second
     *    person approves a credential change
     */
    private const BLOCKED = [
        'settings.security.password',
        'user-password.update',
        'password.confirm',
        'settings.users.profile',
        'settings.users.role',
        'settings.users.reset',
        'settings.users.store',
        'settings.users.toggle',
        'settings.users.destroy',
        'settings.users.requests.approve',
        'settings.users.requests.reject',
        'settings.integrations.*',
        /*
         * Starting a *second* impersonation from inside one would lose the
         * original administrator: the session holds exactly one id.
         *
         * **Named exactly, not as `settings.impersonate.*`.** The wildcard
         * also matched `settings.impersonate.stop` and trapped the
         * administrator inside the session they were trying to leave — the
         * precise failure the route comment warns about, written by the same
         * hand that then wrote the wildcard. A test caught it; reasoning
         * about it twice did not.
         */
        'settings.impersonate.start',
        /*
         * The privacy notice is a *person* saying they have read what is
         * collected about them, and `RequirePrivacyAcknowledgement` writes
         * their name and the date onto the account and into the audit log.
         * An administrator accepting it while wearing somebody else's session
         * would put a legal acknowledgement on file that person never gave —
         * the one row here that is a signature rather than a setting.
         */
        'privacy.acknowledge',
        // Terminating sessions as somebody else would file an incident
        // response under the wrong name, and the actor is the whole point.
        'settings.sessions.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app(ImpersonationService::class)->isImpersonating()) {
            return $next($request);
        }

        if (! $request->routeIs(self::BLOCKED)) {
            return $next($request);
        }

        abort(403, 'Not while impersonating. Stop impersonating first, then do this as yourself.');
    }
}
