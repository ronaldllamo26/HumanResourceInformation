<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication events are audited alongside model changes, and one of them
 * has no subject to point at: a failed login against an address that is not
 * in `users` at all. That is precisely the entry worth keeping — somebody
 * guessing addresses — so `auditable_id` has to accept a null rather than the
 * event being dropped for want of a row to reference.
 *
 * `auditable_type` stays non-null: the *kind* of subject is always known
 * (a user account), even when the specific one is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('auditable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Entries with no subject cannot survive the column becoming NOT NULL,
        // and they are audit records — dropping them silently would be worse
        // than the rollback failing loudly on a stale row.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('auditable_id')->nullable(false)->change();
        });
    }
};
