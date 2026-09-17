<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a login on the Security screen until it has a password its holder
 * chose.
 *
 * A provisioned password is shared by construction — HR reads it out, an admin
 * pastes it into a chat, the seeder prints it to a console someone can scroll
 * back through. Rotating it is the account holder's job, and an instruction to
 * do so is not a control; this is.
 *
 * Web only. The API stack is unaffected on purpose: a Sanctum token is a
 * machine credential on a biometric device, and there is nobody at the other
 * end of it to type a new password. A token belonging to a flagged account is
 * revoked where the password is set instead.
 */
class RequirePasswordChange
{
    /**
     * The three routes a flagged account may still reach.
     *
     * Security is where the password is changed; its PUT is the change itself.
     * Logout is here because trapping someone in a session they cannot leave
     * is worse than the risk being managed — and signing out is the one action
     * that reduces exposure rather than adding to it.
     */
    private const ALLOWED = [
        'settings.security',
        'settings.security.password',
        'otp.challenge',
        'otp.verify',
        'otp.resend',
        'logout',
        'logout.idle',
    ];


    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password || $request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return \Inertia\Inertia::location(route('settings.security'));
        }

        return redirect()->route('settings.security');
    }
}
