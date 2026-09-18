<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who was really at the keyboard when a row was written.
 *
 * Impersonation signs the administrator in *as* somebody else, so from that
 * moment `Auth::id()` is the employee and every `Auditable` row would name
 * them — an administrator's action filed under the person it was done to,
 * with nothing on the row to say otherwise. That is the single most dangerous
 * property of the feature, and it is a data problem rather than a UI one: no
 * banner on a screen survives into the trail an auditor reads a year later.
 *
 * `user_id` therefore keeps meaning "the account the action ran as", which is
 * what every existing query and screen already assumes, and this column
 * carries the administrator behind it. Null is the normal case and means
 * nobody was impersonating.
 *
 * **`AuditLogSigner` covers this column only when it is set**, which is
 * deliberate: appending it unconditionally would change the canonical string
 * of all 1,896 existing rows and report every one of them as altered. Left
 * out of the signature entirely it would be the one field on a tamper-evident
 * row that could be edited without the signature ceasing to match — so a null
 * stays outside the string, and a real impersonator is signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            /*
             * `nullOnDelete` rather than cascade: deleting an administrator's
             * account must not delete the record of what they did, and it must
             * not take the employee's own audit history with it either.
             */
            $table->foreignId('impersonated_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonated_by');
        });
    }
};
