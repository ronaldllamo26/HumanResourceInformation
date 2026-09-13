<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted client has to be recoverable, the same way an employee already is.
 *
 * Clients were the one master-data table that vanished outright. That is worse
 * here than it looks: payslips, attendance, and headcount are all grouped by
 * `client_id`, so removing the row leaves that history pointing at nothing —
 * and there is no undo for a mis-click.
 *
 * Soft-deleting keeps the row, keeps the grouping intact, and gives the
 * archive screen something to restore from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
