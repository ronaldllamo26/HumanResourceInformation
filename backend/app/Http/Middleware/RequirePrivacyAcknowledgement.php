<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in person on the privacy notice until they have read it.
 *
 * RA 10173 asks that people be told what is collected about them, why, who
 * sees it and where it goes before it is processed — and this system holds
 * government numbers, salary and 201-file scans. A link in a footer is a
 * notice nobody reads; this is one everybody has read, with the date recorded.
 *
 * It steps aside while a password change is pending, so the two holds cannot
 * bounce a person between each other: the shared password is replaced first,
 * then the notice is read. Web only, like RequirePasswordChange — a machine's
 * API token has nobody at the other end to read anything.
 */
class RequirePrivacyAcknowledgement
{
    private const ALLOWED = [
        'privacy.notice',
        'privacy.acknowledge',
        'otp.challenge',
        'otp.verify',
        'otp.resend',
        'logout',
        'logout.idle',
        'session.keepalive',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /*
         * An impersonated session is not held, and this is a correctness fix
         * rather than a convenience.
         *
         * The acknowledgement is a **person** saying they have read what is
         * collected about them — so holding an administrator on it would ask
         * them either to abandon the impersonation or to accept a legal notice
         * on somebody else's behalf, and `privacy.acknowledge` is blocked
         * precisely so the second is not possible. Held, the pair left the
         * administrator unable to leave: the hold caught the *stop* request
         * too, so the session could neither go forward nor go back. Found by
         * driving the real route against a live employee who had not
         * acknowledged — the suite missed it because `UserFactory` defaults to
         * acknowledged.
         */
        if (session(ImpersonationService::SESSION_KEY) !== null) {
            return $next($request);
        }

        if (
            ! $user
            || $user->must_change_password
            || $user->hasAcknowledgedPrivacyNotice()
            || $request->routeIs(self::ALLOWED)
        ) {
            return $next($request);
        }

        // Back to where they were headed once they have read it.
        if ($request->isMethod('GET') && ! $request->header('X-Inertia-Partial-Data')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('privacy.notice');
    }
}
