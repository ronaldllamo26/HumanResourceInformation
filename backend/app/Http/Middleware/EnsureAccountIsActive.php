<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a deactivated account out on its very next request.
 *
 * Deactivating deletes the account's database sessions already, but a session
 * kept anywhere else — the file or cookie driver, or one written a moment
 * before the switch — would otherwise carry on until it expired. The account
 * is read fresh from the database on every request, so this sees the change
 * immediately.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'This account has been deactivated. Contact HR if you think this is a mistake.');
    }
}
