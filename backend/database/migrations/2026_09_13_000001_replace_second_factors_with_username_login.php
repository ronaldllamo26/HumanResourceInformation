<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in moves to a username, and both second factors come out.
 *
 * The two earlier migrations that added the OTP and 2FA columns are left in
 * place rather than deleted: they have already run on every database that
 * exists, and removing a migration that ran leaves `migrations` describing a
 * schema the files no longer produce. Undoing them is this migration's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('name');
        });

        /*
         * Every existing account gets a username before the column is made
         * required, or the constraint fails on the first row.
         *
         * Taken from the part of the email before the `@`, which is the name
         * people already recognise as theirs. Inlined rather than calling the
         * model, because a migration has to keep producing the same result
         * after the model has moved on.
         */
        $taken = [];

        DB::table('users')->orderBy('id')->get(['id', 'email'])->each(function ($user) use (&$taken) {
            $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(strstr((string) $user->email, '@', true) ?: ''));
            $base = $base !== '' ? substr($base, 0, 40) : 'user';

            $candidate = $base;

            for ($n = 2; in_array($candidate, $taken, true); $n++) {
                $candidate = $base.$n;
            }

            $taken[] = $candidate;

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->unique('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'otp_enabled',
                'otp_code_hash',
                'otp_expires_at',
                'otp_sent_at',
                'otp_attempts',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->boolean('otp_enabled')->default(false)->after('must_change_password');
            $table->string('otp_code_hash')->nullable()->after('otp_enabled');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code_hash');
            $table->timestamp('otp_sent_at')->nullable()->after('otp_expires_at');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_sent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
