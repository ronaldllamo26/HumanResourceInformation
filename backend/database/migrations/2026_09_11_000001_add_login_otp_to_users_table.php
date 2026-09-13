<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time code emailed at sign-in, as a second factor that needs no app.
 *
 * This system already had a second factor — Fortify's TOTP, a code from an
 * authenticator app — and **zero accounts were enrolled in it**. That is the
 * whole reason this exists rather than being a duplicate of it: TOTP asks
 * somebody to install an app, scan a QR code, and keep recovery codes
 * somewhere safe before it protects anything, and a control nobody completes
 * the setup for is a control that is switched off. An emailed code asks for
 * an inbox they already have open.
 *
 * **The code is hashed, not stored.** A live six-digit code sitting in
 * plaintext in `users` would mean a database dump hands over working second
 * factors for every account currently signing in — which would make the
 * column a better target than the password column beside it, since a password
 * hash cannot be replayed and a plaintext OTP can. Hashed, a dump proves only
 * that somebody was mid-login.
 *
 * `otp_attempts` is the half that makes six digits defensible at all. A
 * million combinations is a lot for a person and nothing for a script: with
 * unlimited guesses inside the expiry window, the code is decoration. Five
 * wrong answers burn it and a new one has to be sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-account, and off by default: turning this on for everybody
            // in a migration would hold every existing login behind a code
            // the mailer may not be configured to deliver yet.
            $table->boolean('otp_enabled')->default(false)->after('must_change_password');

            $table->string('otp_code_hash')->nullable()->after('otp_enabled');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code_hash');

            // Separate from `otp_expires_at` because they answer different
            // questions: expiry decides whether a code still works, and this
            // decides whether another one may be sent yet.
            $table->timestamp('otp_sent_at')->nullable()->after('otp_expires_at');

            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'otp_enabled',
                'otp_code_hash',
                'otp_expires_at',
                'otp_sent_at',
                'otp_attempts',
            ]);
        });
    }
};
