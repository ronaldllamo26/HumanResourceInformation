<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time & Attendance, rebuilt: seven submodules on nine tables.
 *
 * Shifts & rest days, the holiday calendar, daily time records, overtime
 * requests, time corrections, cutoff closing, and client timesheets. The
 * earlier module's tables were dropped by 2026_09_15_000003; these are new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Which shift an employee works, and which weekdays are their rest days.
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->json('rest_days');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->string('type', 20);
            $table->timestamps();

            $table->unique(['date', 'name']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('minutes_worked')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('night_diff_minutes')->default(0);
            $table->string('source', 20)->default('manual');
            $table->string('remarks', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'work_date']);
        });

        Schema::create('time_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_remarks', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'work_date']);
        });

        // A payroll period's attendance, locked once it has been checked.
        Schema::create('attendance_cutoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('client_timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('confirmed_by_name')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('client_remarks', 1000)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'payroll_period_id']);
        });

        Schema::create('client_timesheet_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('days_worked', 5, 2)->default(0);
            $table->decimal('hours_worked', 7, 2)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->decimal('absent_days', 5, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['client_timesheet_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_timesheet_lines');
        Schema::dropIfExists('client_timesheets');
        Schema::dropIfExists('attendance_cutoffs');
        Schema::dropIfExists('time_corrections');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('shifts');
    }
};
