<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify supplies the authentication backend; this app supplies the screens.
 *
 * Fortify ships routes, validation, and the session handling for logging in
 * in, but no views — which suits an Inertia app, because
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

        // Normalize login input so requests with either 'username' or 'email' work seamlessly
        if (! $this->app->runningInConsole()) {
            $input = request()->input('username') ?? request()->input('email');
            if ($input !== null) {
                request()->merge([
                    'email' => $input,
                    'username' => $input,
                ]);
            }
        }

        $this->refuseDeactivatedAccounts();
        $this->registerViews();
    }

    /**
     * A deactivated account cannot sign in on the web.
     *
     * Fortify's default check is only username and password, so switching an
     * account off in Users & Access, or an employee resigning, used to change
     * nothing at the login screen. The API login already refused them.
     *
     * The "deactivated" message is shown only once the password has matched,
     * so it tells nobody guessing whether an account exists.
     */
    private function refuseDeactivatedAccounts(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $login = (string) $request->input(Fortify::username());
            $user = User::where('username', $login)
                ->orWhere('email', $login)
                ->orWhere('username', strstr($login, '@', true) ?: $login)
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                event(new Failed('web', $user, $request->only(Fortify::username())));

                throw ValidationException::withMessages([
                    Fortify::username() => 'This account has been deactivated. Contact HR if you think this is a mistake.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Points Fortify at the Inertia pages that already exist.
     *
     * Nothing here is new UI — these are the same components the hand-written
     * controllers rendered, so the switch is invisible to anyone using the app.
     */
    private function registerViews(): void
    {
        // No reset link: accounts carry no email to send one to. A forgotten
        // password is reset by an administrator on Settings > Users & Access.
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => session('status'),
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
