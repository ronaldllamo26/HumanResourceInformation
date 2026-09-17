<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireOtp;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequirePrivacyAcknowledgement;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'login',
            'logout',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Before the two holds below: a deactivated account is signed out,
            // not asked to change its password or read the privacy notice.
            EnsureAccountIsActive::class,
            /*
             * Before both holds below: a session that has not answered its
             * sign-in code should not be able to change the account's password
             * or accept the privacy notice on its behalf.
             */
            RequireOtp::class,
            // After HandleInertiaRequests, so the redirect it issues is still
            // an Inertia response rather than a full page load.
            RequirePasswordChange::class,
            RequirePrivacyAcknowledgement::class,
        ]);

        // Both stacks: the API serves JSON to biometric devices and
        // integrations, and nosniff matters as much there as on a screen.
        $middleware->append(SecurityHeaders::class);

        /*
         * Whose `X-Forwarded-*` headers to believe.
         *
         * Unset, Laravel reads the *connecting* address — which behind a
         * reverse proxy is the proxy, on every single request. This domain is
         * Cloudflare-fronted and Hostforge serves from behind its own proxy,
         * so four things in this codebase would quietly record or key off the
         * wrong value:
         *
         *   - `RecordAuthenticationEvents` logs `$request->ip()` for every
         *     sign-in and every failed one. CLAUDE.md says that trail exists
         *     to show "what somebody guessing at addresses looks like" — and
         *     one proxy IP on all of them shows nothing.
         *   - `DataAccessLogger` logs the reader's IP for every 201-file
         *     document opened. That is the RA 10173 accountability trail.
         *   - `Auditable` logs it for every record change.
         *   - `defineApiRateLimit()` falls back to `by('ip:'.$request->ip())`
         *     for callers with no bearer token, which would drop the entire
         *     internet into one shared 20/min bucket rather than one each.
         *
         * And `$request->secure()` stays false over a proxy-terminated TLS
         * connection, so `SecurityHeaders` would never send HSTS in
         * production and `url()` would generate `http://` links.
         *
         * Read from env and **empty by default**, which is the safe direction:
         * trusting a header nobody is stripping lets a caller claim any IP
         * they like, so this is only switched on where a proxy really does
         * sit in front. Locally there is none, and Herd talks to PHP
         * directly. Production sets `TRUSTED_PROXIES=*` — correct there
         * because the origin is only reachable through the proxy, which
         * overwrites the header rather than passing a client's own through.
         */
        $proxies = env('TRUSTED_PROXIES', env('APP_ENV') === 'production' ? '*' : null);

        if (! empty($proxies)) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : explode(',', $proxies),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                if ($request->header('X-Inertia')) {
                    return \Inertia\Inertia::location(route('login'));
                }

                return redirect()->guest(route('login'), 303);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException && $request->is('login')) {
                if ($request->header('X-Inertia')) {
                    return \Inertia\Inertia::location(route('login'));
                }

                return redirect()->route('login', [], 303);
            }

            return $response;
        });
    })->create();
