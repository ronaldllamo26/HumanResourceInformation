<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns Fortify's two-factor authentication keeps.
 *
 * Copied from the package's own published migration rather than published
 * from it, so the file sits in the same folder as every other migration this
 * project runs and its date orders with them. The columns and types are
 * Fortify's — the package reads them by name.
 *
 * `two_factor_secret` and `two_factor_recovery_codes` are `text` because
 * Fortify encrypts both before they are written: what is stored is ciphertext,
 * not a 32-character base32 seed. **Losing `APP_KEY` therefore locks every
 * enrolled account out of its second factor**, which is one more reason that
 * key is the thing to back up before anything else in this system.
 *
 * `two_factor_confirmed_at` is what separates "started setting it up" from
 * "it works". Fortify writes the secret the moment somebody asks to enable
 * 2FA, and only sets this once they have typed a code from their app — so a
 * person who closes the tab halfway is not locked out by a factor they never
 * finished proving they hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->after('password')->nullable();
            $table->text('two_factor_recovery_codes')->after('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
