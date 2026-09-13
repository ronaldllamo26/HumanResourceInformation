<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exit half of the employee lifecycle.
 *
 * Figures are **snapshotted** on the row rather than recomputed on read, for
 * the same reason payslips are: what was released is what was released, and a
 * later change to salary, leave credits, or a loan balance must not silently
 * rewrite a settlement already handed over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('separations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('last_day');
            // resigned | terminated | end_of_contract | retirement
            $table->string('reason', 32);
            // draft | cleared | released
            $table->string('status', 16)->default('draft');

            // --- Final pay, as computed at the time ---
            $table->decimal('unpaid_salary', 12, 2)->default(0);
            $table->decimal('thirteenth_month', 12, 2)->default(0);
            $table->decimal('leave_conversion', 12, 2)->default(0);
            $table->decimal('loan_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_final_pay', 12, 2)->default(0);

            // The working shown on screen, kept with the figure it explains.
            $table->json('breakdown')->nullable();
            // Checklist state: [{key, label, blocking, cleared_at}]
            $table->json('clearance')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One open separation per employee; re-hiring creates a new row
            // only after the previous one is released.
            $table->index(['employee_id', 'status']);
            $table->index('last_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separations');
    }
};
