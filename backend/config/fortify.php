<?php

use Laravel\Fortify\Features;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    /*
     * People sign in with a username, not an email address. A company
     * account should not depend on somebody's personal inbox, and the role
     * already lives on the account — so `admin`, `hr` and `jdelacruz` are
     * enough to say who is signing in and what they may open.
     *
     * `email` below is unchanged: the forgot-password flow still sends its
     * link to the address on the account.
     */
    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    /*
    | This app has no /home — the landing page after signing in is the
    | dashboard, and Fortify's default sent every successful login to a route
    | that does not exist.
    */
    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    /*
    | `login` is deliberately null, which is not the same as unlimited.
    |
    | Naming a limiter here makes Fortify apply the `throttle:login`
    | *middleware* and skip `EnsureLoginIsNotThrottled` — see
    | AuthenticatedSessionController:86. The middleware refuses the request
    | with a 429 and fires nothing; the action refuses it *and* fires
    | Laravel's `Lockout` event, which is what
    | `RecordAuthenticationEvents` writes to the audit log.
    |
    | That entry is the one worth keeping: a 429 in a web-server log says
    | somebody was throttled, while the audit row says which account was being
    | guessed at. Same five attempts either way — Fortify's LoginRateLimiter
    | hard-codes the threshold — so nulling this costs no strictness and buys
    | the trail.
    |
    | Two-factor and passkeys are off in `features`, so their limiters are
    | unreachable and left out rather than configured for routes that do not
    | exist.
    */
    'limiters' => [
        'login' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Passkeys
    |--------------------------------------------------------------------------
    |
    | These settings configure Fortify's passkey (WebAuthn) support. Passkeys
    | allow users to sign in without needing to remember credentials since
    | they use public-key cryptography - making them immune to breaches.
    |
    */

    'passkeys' => [
        'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [config('app.url')],
        'timeout' => 60000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features or you can even remove all of these if you need to.
    |
    */

    /*
    | Trimmed to what this system actually offers, and the omissions matter
    | more than the inclusions:
    |
    | - **registration is off.** Self-registration is disabled by design — HR
    |   provisions every login from the employee form, because an account here
    |   has to be tied to a 201 file and given a role. Fortify enables it by
    |   default, which reopened `/register` the moment it was installed and
    |   turned a deliberate 404 into a live signup page.
    | - **two-factor is off, and so are passkeys.** Both second factors were
    |   removed from this system — the authenticator app and the emailed code
    |   — so a password is the whole front door again. That is a known gap on
    |   a system holding salary and government identifiers, and it is the
    |   first thing to restore before this runs with real employee data.
    | - **emailVerification stays off**, matching the app's own routes: HR
    |   creates verified accounts, so there is nobody to verify.
    | - **updateProfileInformation is off.** Settings > Security owns that,
    |   and it guards `email_verified_at` on an email change — a rule
    |   Fortify's generic action does not know about.
    */
    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
    ],

];
