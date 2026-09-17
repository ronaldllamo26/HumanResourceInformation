<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sign-in code (OTP)
    |--------------------------------------------------------------------------
    |
    | The second factor: after the username and password, an account with a
    | personal email connected to it is asked for a six-digit code sent to
    | that inbox. Every number here is a trade-off between a window an
    | attacker can work inside and a window a person can actually finish in,
    | so they live in config rather than in the service — tightening one is an
    | edit here, not a code change.
    |
    */

    /*
     * The master switch, and it is the way back in when mail breaks.
     *
     * With no terminal on the deployment host and no emailed password reset,
     * a misconfigured mailer plus a required code would lock every account out
     * of the system with no recovery at all. Setting OTP_ENABLED=false in the
     * Hostforge panel drops the factor for everybody and leaves the username
     * and password working, which is the one escape hatch that does not need
     * a shell. Nothing else in this file can cause that, and nothing else
     * should.
     */
    'enabled' => (bool) env('OTP_ENABLED', true),

    /*
     * Six digits, because that is what people expect a code to look like and
     * what they will copy out of an email without mistyping. It is only
     * defensible alongside `max_attempts` below: a million combinations is a
     * lot for a person and nothing for a script.
     */
    'length' => (int) env('OTP_LENGTH', 6),

    /*
     * Two minutes (120 seconds), which is what the owner requested.
     */
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 120),

    /*
     * Five wrong answers burn the code and force a new one. This is what makes
     * six digits a factor rather than a formality — without it, the expiry
     * window is simply how long a script has to try every combination.
     */
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
     * The gap before another code may be sent (30 seconds).
     */
    'resend_after_seconds' => (int) env('OTP_RESEND_AFTER_SECONDS', 30),

];
