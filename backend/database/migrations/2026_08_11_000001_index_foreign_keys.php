<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the foreign keys the application actually joins and filters on.
 *
 * Postgres does not index a foreign key for you — only the primary key side.
 * Every one of these columns was a sequential scan: the supervisor scoping in
 * EmployeeService::scopedQuery() runs on every request a supervisor makes,
 * scopedPayslipQuery() filters payslips by employee, and the Timekeeping
 * History screen joins audit_logs to users. They are cheap now because the
 * tables are small; attendance_logs alone grows by roughly one row per
 * employee per day.
 *
 * The un-indexed foreign keys left alone are on small lookup tables that are
 * read whole and rarely written — departments.head_employee_id,
 * positions.department_id, kpis.department_id, kpis.position_id — where an
 * index costs write time and storage for a scan Postgres would prefer anyway.
 */
return new class extends Migration
{
    /**
     * table => [foreign key columns to index]
     *
     * @var array<string, array<int, string>>
     */
    private const INDEXES = [
        'employees' => ['department_id', 'position_id', 'supervisor_id'],
        'employee_documents' => ['uploaded_by'],
        'employee_schedules' => ['shift_id'],
        'attendance_logs' => ['shift_id', 'approved_by'],
        'overtime_requests' => ['approved_by'],
        'leave_balances' => ['leave_type_id'],
        'leave_requests' => ['leave_type_id', 'supervisor_id', 'hr_id'],
        'payroll_runs' => ['payroll_period_id', 'processed_by', 'approved_by'],
        'payslips' => ['employee_id'],
        'audit_logs' => ['user_id'],
        'employee_kpis' => ['kpi_id', 'review_cycle_id'],
        'performance_reviews' => ['review_cycle_id', 'reviewer_id'],
        'performance_review_ratings' => ['kpi_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->index($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropIndex("{$table}_{$column}_index");
                    }
                }
            });
        }
    }
};
