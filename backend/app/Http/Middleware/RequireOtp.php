<?php

namespace App\Http\Middleware;

use App\Services\OtpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in session on the code screen until the code is answered.
 *
 * A password alone is one secret, and the one most likely to be reused,
 * phished, or read off the chat message it was handed over in. This asks for
 * something the sign-in attempt has to be able to *receive*, which a stolen
 * password on its own cannot.
 *
 * **The hold is on the session, not the account.** The flag that matters is
 * `otp.verified_at` in the session, so signing in from a second machine asks
 * again and signing out forgets it — which is the behaviour somebody expects
 * from a factor that exists to notice a login they did not make.
 *
 * **It runs before `RequirePasswordChange` and the privacy hold**, because a
 * session that has not cleared its second factor should not be able to change
 * the account's password or accept anything on its behalf.
 *
 * Web only, and for the same reason `RequirePasswordChange` is: a Sanctum
 * token is a machine credential on a biometric device with no inbox and
 * nobody at the other end to read one.
 */
class RequireOtp
{
    /**
     * The routes a held session may still reach.
     *
     * The screen, the two actions on it, and logout. Logout is here for the
     * reason it is on `RequirePasswordChange`: trapping somebody in a session
     * they cannot leave is worse than the risk being managed, and signing out
     * reduces exposure rather than adding to it.
     */
    private const ALLOWED = ['otp.challenge', 'otp.verify', 'otp.resend', 'logout', 'logout.idle'];

    public function __construct(private readonly OtpService $otp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $this->otp->isRequiredFor($user) || $request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        if ($request->session()->has(OtpService::SESSION_KEY)) {
            return $next($request);
        }

        /*
         * The first held request is what sends the code, rather than the login
         * controller.
         *
         * Putting it here means every way into a session is covered by one
         * rule — the login form, a session that was authenticated before an
         * address was connected, a tab left open across a config change.
         * Hooking the login event instead would let the last two walk straight
         * past a screen that was never shown.
         */
        if (! $this->otp->hasLiveCode($user) && $this->otp->canResend($user)) {
            $this->otp->send($user);
        }

        // Redirected rather than aborted: the reader is allowed here, one step
        // early — the same distinction `RequirePasswordChange` draws.
        return redirect()->route('otp.challenge');
    }
}
