<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the bank said, on the run it paid.
 *
 * `status = paid` was the only record that money had actually moved, and a
 * status column cannot answer "which transfer paid this run" — which is the
 * question a reconciliation asks first. Finance confirms the disbursement over
 * `POST /payroll/runs/{run}/disbursement` and these three columns are what it
 * leaves behind.
 *
 * The loop was open before this: `/register` handed Finance a list and nothing
 * came back, so a run sat at `approved` until somebody in HR remembered to
 * tick it — and *approved* and *the money arrived* are different facts the
 * system was reporting as one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // The bank's own reference, not ours. Nullable because every run
            // that already exists was marked paid by hand, before there was
            // anywhere to record this.
            $table->string('disbursement_reference', 120)->nullable()->after('remarks');

            /*
             * When the bank credited it, which is not when this row was
             * written. A transfer sent on Friday and confirmed on Monday is
             * one event with two dates, and `updated_at` would only ever hold
             * the second.
             */
            $table->timestamp('disbursed_at')->nullable()->after('disbursement_reference');

            $table->text('disbursement_notes')->nullable()->after('disbursed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['disbursement_reference', 'disbursed_at', 'disbursement_notes']);
        });
    }
};
