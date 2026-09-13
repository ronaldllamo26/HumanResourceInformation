<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 — requests to correct a DTR day.
 *
 * HR can already edit a time record directly; employees cannot, and never
 * should be able to — a DTR somebody can rewrite is not a record of anything.
 * This is their route to a correction: they say what the day should have said
 * and why, and a supervisor or HR decides before it touches the log.
 *
 * The request holds only what was *asked for*. What the day currently says is
 * read live on the decision screen rather than snapshotted here, so an
 * approver is looking at the record as it stands rather than at how it looked
 * when the request was filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            /*
             * The day, not the row. A missing punch is one thing to correct;
             * a day nobody keyed at all is another, and it has no row to point
             * at. Both are `record(employee, date)` at the other end, so the
             * date is the honest key.
             */
            $table->date('log_date');

            // "HH:MM", or null to leave that punch alone / clear it.
            $table->time('requested_time_in')->nullable();
            $table->time('requested_break_out')->nullable();
            $table->time('requested_break_in')->nullable();
            $table->time('requested_time_out')->nullable();
            // An explicit status override — on_leave, absent — where punches
            // are not the point.
            $table->string('requested_status', 24)->nullable();

            $table->text('reason');

            // pending | approved | rejected | cancelled
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'log_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');
    }
};
