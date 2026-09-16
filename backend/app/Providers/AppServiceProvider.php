<?php

namespace App\Providers;

use App\Listeners\RecordAuthenticationEvents;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (request()->header('X-Forwarded-Proto') === 'https' || request()->isSecure() || app()->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        if (request()->header('Host')) {
            $scheme = request()->header('X-Forwarded-Proto') ?? (request()->isSecure() ? 'https' : 'http');
            config(['app.url' => $scheme . '://' . request()->header('Host')]);
        }

        Vite::prefetch(concurrency: 3);

        $this->definePasswordPolicy();

        // Model changes are audited by the Auditable trait; sign-ins are
        // audited here. Registered explicitly rather than by discovery so the
        // wiring is visible from the provider.
        Event::subscribe(RecordAuthenticationEvents::class);

        $this->defineApiRateLimit();

        if (windows_os() && class_exists(\Illuminate\Foundation\Console\ServeCommand::class)) {
            \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables = array_unique(array_merge(
                \Illuminate\Foundation\Console\ServeCommand::$passthroughVariables,
                [
                    'SystemRoot',
                    'SystemDrive',
                    'WINDIR',
                    'COMSPEC',
                    'PATHEXT',
                    'TEMP',
                    'TMP',
                    'USERPROFILE',
                    'ALLUSERSPROFILE',
                    'ProgramData',
                    'LOCALAPPDATA',
                    'APPDATA',
                    'HOMEDRIVE',
                    'HOMEPATH',
                ],
                array_keys($_ENV),
                array_keys($_SERVER),
            ));
        }
    }

    /**
     * Caps how fast a token can pull data out of `/api/v1`.
     *
     * Every endpoint there was already gated correctly — the hole was that a
     * *correctly authorised* token had no ceiling. The API serves the employee
     * directory and 201-file documents, so an unthrottled token can walk the
     * whole workforce's personal data as fast as the server answers. These are
     * unattended machine credentials sitting on biometric devices, which is
     * exactly the credential most likely to be copied off a device and least
     * likely to be noticed.
     *
     * Keyed by token, not by user: two devices sharing one account should not
     * throttle each other, and a single stolen token should not get the whole
     * account's budget. Falls back to the address for an unauthenticated hit
     * — mostly the login endpoint, which carries its own tighter limit.
     *
     * A device syncing 39 employees pages that in three requests, so the
     * ceiling below is far above real use and only bites a scrape.
     */
    private function defineApiRateLimit(): void
    {
        RateLimiter::for('api', function (Request $request) {
            /*
             * The bearer string is read directly rather than through
             * `$request->user()`, because `ThrottleRequests` carries a
             * middleware priority and `Authenticate` does not — so the limiter
             * can be asked for its key before authentication has resolved a
             * user, and keying off a null user silently drops every token into
             * one shared per-address bucket. Hashing the token the caller
             * actually sent needs no such ordering to hold.
             */
            $bearer = $request->bearerToken();

            return $bearer
                ? Limit::perMinute((int) config('sanctum.rate_limit', 60))
                    ->by('token:'.hash('sha256', $bearer))
                : Limit::perMinute(20)->by('ip:'.$request->ip());
        });
    }

    /**
     * The rule behind every `Password::defaults()` in the app — the four auth
     * controllers and the Security screen all defer to this.
     *
     * Unconfigured, `defaults()` means `min:8` and nothing else, which is thin
     * for accounts that can read every employee's salary, TIN, and bank
     * account. Twelve characters with mixed case and a digit is the floor;
     * `Str::password(12)` — what EmployeeService hands HR when it provisions a
     * login — already clears it, so provisioning is unaffected.
     */
    private function definePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            // uncompromised() calls the Have I Been Pwned range API. Worth the
            // round trip in production; in tests it would make every password
            // assertion depend on the network, and locally it fails open
            // anyway, so it earns nothing but latency there.
            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });
    }
}
