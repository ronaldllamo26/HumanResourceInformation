<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which privacy notice each person has read, and when.
 *
 * The version is stored as well as the date so a changed notice can be shown
 * again: an acknowledgement of last year's wording is not consent to this
 * year's. Existing accounts start with neither, so everybody reads it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('privacy_notice_version', 32)->nullable()->after('must_change_password');
            $table->timestamp('privacy_acknowledged_at')->nullable()->after('privacy_notice_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_notice_version', 'privacy_acknowledged_at']);
        });
    }
};
