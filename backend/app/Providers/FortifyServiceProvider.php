<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify supplies the authentication backend; this app supplies the screens.
 *
 * Fortify ships routes, validation, and the session handling for logging in
 * and resetting a password, but no views — which suits an Inertia app, because
 * the pages already exist as React components and only need to be pointed at.
 *
 * Three things this system already decided had to survive the switch:
 *
 * - **Nobody self-registers.** `Features::registration()` is off in
 *   `config/fortify.php`; HR provisions every login from the employee form,
 *   because an account here has to be tied to a 201 file and given a role.
 * - **Sign-ins stay audited.** `RecordAuthenticationEvents` listens to
 *   Laravel's own `Login`, `Logout`, `Failed`, and `Lockout` events, which
 *   Fortify fires exactly as the hand-written controllers did — so the audit
 *   trail needed no change at all.
 * - **The password floor is still set once.** Fortify's
 *   `PasswordValidationRules` calls `Password::default()`, which is the same
 *   callback `AppServiceProvider::definePasswordPolicy()` registers. Both
 *   halves of the app read one rule.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, function () {
            return new class implements \Laravel\Fortify\Contracts\LoginResponse {
                public function toResponse($request)
                {
                    if ($request->wantsJson()) {
                        return response()->json([
                            'two_factor' => false,
                            'user' => $request->user()?->only(['id', 'name', 'email', 'role']),
                        ]);
                    }

                    return redirect()->intended(config('fortify.home', '/dashboard'));
                }
            };
        });

        $this->app->singleton(\Laravel\Fortify\Contracts\LogoutResponse::class, function () {
            return new class implements \Laravel\Fortify\Contracts\LogoutResponse {
                public function toResponse($request)
                {
                    if ($request->wantsJson()) {
                        return response()->json(['message' => 'Logged out.']);
                    }

                    return redirect('/');
                }
            };
        });

        $this->registerViews();
    }

    /**
     * Points Fortify at the Inertia pages that already exist.
     *
     * Nothing here is new UI — these are the same components the hand-written
     * controllers rendered, so the switch is invisible to anyone using the app.
     */
    private function registerViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'status' => session('status'),
        ]));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));

        // Password confirmation is not an optional Fortify feature — it is
        // always registered — so the app's own /confirm-password routes were
        // duplicates the moment Fortify was installed, and Fortify's won the
        // `password.confirm` name. Rather than leave two implementations with
        // one unreachable, the hand-written pair is gone and this points
        // Fortify at the page they used to render.
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

    }
}
