<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Left exactly as Sanctum ships it. The UI is Inertia over the session
    | guard and /api/v1 is token-authenticated; adding this app's own host
    | here would make browser requests to the API stateful, which is a change
    | to how the API authenticates and is not what this file is for.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:5173,localhost:3000,127.0.0.1,127.0.0.1:5173,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Sanctum's own default here is null — tokens that never expire. For the
    | biometric-device and integration tokens issued from Settings, that means
    | a credential copied onto a device in a depot stays valid forever, long
    | after the device is retired or the contractor who installed it has gone.
    |
    | A year is deliberately generous rather than tight: these are unattended
    | machine credentials, and an expiry short enough to be inconvenient is an
    | expiry somebody works around by never rotating. The Settings screen shows
    | the expiry date, so a token running out is visible before it stops a
    | night's punches from importing.
    |
    | Existing tokens are covered too — Sanctum measures from `created_at`, so
    | this applies to what is already issued, not just to new tokens.
    |
    */

    'expiration' => (int) env('SANCTUM_EXPIRATION_MINUTES', 60 * 24 * 365),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | A prefix lets secret-scanning services recognise a leaked token in a
    | commit or a paste and alert on it.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'pphris_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests per minute, per token
    |--------------------------------------------------------------------------
    | Applied by `throttle:api`, defined in AppServiceProvider. A biometric
    | device pages 39 employees in three requests and posts one row per punch,
    | so this sits far above real use — it exists to stop a copied token from
    | walking the whole 201-file archive, not to pace a device.
    |
    | Per token rather than per user: two devices on one service account must
    | not throttle each other, and one stolen token must not inherit the whole
    | account's budget.
    */
    'rate_limit' => (int) env('SANCTUM_RATE_LIMIT', 60),

];
