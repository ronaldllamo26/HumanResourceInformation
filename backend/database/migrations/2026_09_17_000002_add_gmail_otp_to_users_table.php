<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time code, emailed to the account holder's own inbox.
 *
 * The company username (`admin@primepower.com`) is a *credential*, not an
 * address — nothing is ever mailed to it, which is why the login lost its
 * email in the first place. A second factor needs somewhere a code can
 * actually arrive, so each account gets **one personal address, connected by
 * an administrator on Users & Access**: the login stays the company username,
 * and the code goes to the person's own Gmail.
 *
 * `otp_email` is therefore the enrolment. There is no `otp_enabled` flag
 * beside it, deliberately — two ways to say "this account uses a code" would
 * eventually disagree, and "has an inbox to send to" is the honest condition.
 * The global switch is `OTP_ENABLED`, which exists as the way back in if mail
 * breaks; see `config/otp.php`.
 *
 * **The code is hashed, never stored.** A live six-digit code sitting in
 * plaintext would make this column a better target than the password hash
 * beside it: a password hash cannot be replayed and a plaintext code can.
 * Hashed, a database dump proves only that somebody was mid-login.
 *
 * `otp_attempts` is what makes six digits a factor rather than a formality. A
 * million combinations is a lot for a person and nothing for a script, so five
 * wrong answers burn the code and a new one has to be sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Not unique: two accounts legitimately share a family inbox, and
            // a unique index would refuse the second with an error about a
            // column nobody outside this file has heard of.
            $table->string('otp_email')->nullable()->after('must_change_password');

            // Set the first time a code sent there is answered correctly —
            // which is the only evidence that the address is really the
            // person's and was typed without a slip.
            $table->timestamp('otp_email_verified_at')->nullable()->after('otp_email');

            $table->string('otp_code_hash')->nullable()->after('otp_email_verified_at');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code_hash');

            // Separate from `otp_expires_at` because they answer different
            // questions: expiry decides whether a code still works, this
            // decides whether another one may be sent yet.
            $table->timestamp('otp_sent_at')->nullable()->after('otp_expires_at');

            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'otp_email',
                'otp_email_verified_at',
                'otp_code_hash',
                'otp_expires_at',
                'otp_sent_at',
                'otp_attempts',
            ]);
        });
    }
};
