<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 — Timekeeping & Attendance.
 * Payroll reads late/undertime/overtime minutes from `attendance_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->unsignedSmallInteger('grace_period_minutes')->default(15);
            $table->boolean('is_night_shift')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // ISO weekday numbers the shift applies to, e.g. [1,2,3,4,5]
            $table->json('days_of_week');
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date');
            // regular | special_non_working
            $table->string('type', 32)->default('regular');
            $table->boolean('is_nationwide')->default(true);
            $table->timestamps();

            $table->unique(['date', 'name']);
        });

        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('log_date');

            $table->dateTime('time_in')->nullable();
            $table->dateTime('break_out')->nullable();
            $table->dateTime('break_in')->nullable();
            $table->dateTime('time_out')->nullable();

            // biometric | manual | web | import
            $table->string('source', 24)->default('manual');
            // present | absent | late | undertime | on_leave | holiday | rest_day
            $table->string('status', 24)->default('present');

            $table->decimal('hours_worked', 6, 2)->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('undertime_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->unsignedSmallInteger('night_diff_minutes')->default(0);

            $table->string('biometric_device_id', 64)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One canonical DTR row per employee per day.
            $table->unique(['employee_id', 'log_date']);
            $table->index('log_date');
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->decimal('hours', 6, 2)->default(0);
            $table->text('reason');
            // pending | approved | rejected | cancelled
            $table->string('status', 24)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('employee_schedules');
        Schema::dropIfExists('shifts');
    }
};
