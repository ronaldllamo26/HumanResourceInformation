<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a login whose password was chosen by somebody else.
 *
 * Three paths hand a person a password they did not pick: the seeder, HR
 * creating a login from the employee form, and an admin resetting one in
 * Users & Access. All three deliver it through a channel that keeps a copy —
 * a chat message, a spoken sentence, a terminal that stays scrolled back — so
 * the password is known to at least two people from the moment it exists.
 *
 * The flag is what makes that temporary rather than permanent: the account
 * cannot reach any screen until its holder has set a password of their own.
 * Defaulting to false is deliberate — every login that already exists chose
 * its own password or has already been living with this, and locking the
 * whole table out on deploy would be a worse failure than the one being fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
