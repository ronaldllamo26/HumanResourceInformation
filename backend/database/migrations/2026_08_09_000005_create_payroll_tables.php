<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — Payroll & Compensation.
 * Consumes Timekeeping (OT / late / undertime) and Leave (unpaid leave days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');                              // e.g. Transportation, Meal, COLA
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 24)->default('monthly'); // monthly | semi_monthly | per_payroll
            $table->boolean('is_taxable')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);                          // sss | pagibig | company | salary_advance
            $table->string('reference_number', 64)->nullable();
            $table->decimal('principal_amount', 12, 2);
            $table->decimal('monthly_amortization', 12, 2);
            $table->decimal('outstanding_balance', 12, 2);
            $table->date('start_date');
            $table->string('status', 24)->default('active');     // active | paid | cancelled
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('pay_date');
            $table->string('frequency', 24)->default('semi_monthly');
            // draft | processing | for_approval | approved | paid | cancelled
            $table->string('status', 24)->default('draft');
            $table->timestamps();

            $table->unique(['start_date', 'end_date']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('run_number', 32)->unique();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('payslip_number', 32)->unique();

            // --- Attendance snapshot (pulled from Module 2) ---
            $table->decimal('days_worked', 6, 2)->default(0);
            $table->decimal('hours_worked', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('night_diff_hours', 8, 2)->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('undertime_minutes')->default(0);
            $table->decimal('absent_days', 6, 2)->default(0);
            $table->decimal('unpaid_leave_days', 6, 2)->default(0);

            // --- Earnings ---
            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('night_diff_pay', 12, 2)->default(0);
            $table->decimal('holiday_pay', 12, 2)->default(0);
            $table->decimal('allowances_total', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);

            // --- Employee-share statutory deductions ---
            $table->decimal('sss_employee', 12, 2)->default(0);
            $table->decimal('philhealth_employee', 12, 2)->default(0);
            $table->decimal('pagibig_employee', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);

            // --- Employer share (reporting only, not deducted) ---
            $table->decimal('sss_employer', 12, 2)->default(0);
            $table->decimal('philhealth_employer', 12, 2)->default(0);
            $table->decimal('pagibig_employer', 12, 2)->default(0);

            // --- Other deductions ---
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('undertime_deduction', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('loans_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);

            $table->decimal('deductions_total', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);        // earning | deduction
            $table->string('code', 48);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['payslip_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('employee_allowances');
    }
};
