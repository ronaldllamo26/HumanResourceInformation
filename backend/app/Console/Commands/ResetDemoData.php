<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empties the system of its demonstration data, keeping the way back in.
 *
 * The seeded workforce — 42 employees, their clients, attendance, leave,
 * payroll, reviews and documents — is what makes a fresh clone worth looking
 * at and exactly what has to go before the first real employee is filed. Doing
 * it by hand is 30-odd tables in an order the foreign keys care about; doing
 * it with `migrate:fresh` takes the schema and the admin login with it.
 *
 * **What survives is deliberate and short.** The administrator's own login
 * (there is no terminal on the deployment host and no emailed reset, so an
 * account-less database is a locked door), the company settings, and the
 * master data that has to exist before anything can be keyed at all: leave
 * types, shifts, holidays. Everything else goes.
 *
 * **It refuses to run without `--force`, and says what it would delete
 * instead.** A command that empties a payroll system on a typo is a command
 * nobody should have written.
 */
class ResetDemoData extends Command
{
    /**
     * Cleared in this order, children before parents.
     *
     * Postgres would enforce most of this for us and SQLite (the test driver)
     * would not, so the order is written down rather than left to the engine —
     * and a table that does not exist is skipped, so this survives a migration
     * that has not run yet.
     *
     * @var array<int, string>
     */
    private const TABLES = [
        // Time & Attendance
        'client_timesheet_lines',
        'client_timesheets',
        'attendance_cutoffs',
        'time_corrections',
        'overtime_requests',
        'attendance_logs',
        'employee_shifts',

        // Payroll
        'payslip_lines',
        'payslips',
        'payroll_runs',
        'payroll_periods',
        'payroll_adjustments',
        'employee_loan_payments',
        'employee_loans',
        'employee_allowances',
        'salary_adjustments',
        'separation_clearance_items',
        'separations',

        // Leave
        'leave_requests',
        'leave_balances',

        // Performance
        'performance_review_ratings',
        'performance_reviews',
        'employee_scorecard_items',
        'employee_scorecards',
        'review_cycles',
        'kpis',

        // Module 1 — the people and everything filed against them
        'document_scans',
        'employee_documents',
        'employee_education',
        'employee_trainings',
        'employee_skills',
        'employee_license_verifications',
        'disciplinary_actions',
        'employee_endorsements',
        'employees',
        'clients',
        'positions',
        'departments',
    ];

    protected $signature = 'hris:reset-demo-data
                            {--force : Actually delete. Without this, the command only reports.}
                            {--keep-audit : Leave the audit trail alone.}';

    protected $description = 'Delete the demonstration data, keeping the admin login, settings and master data';

    public function handle(): int
    {
        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->first();

        if (! $admin) {
            $this->error('No administrator account — refusing to run, because nothing would be left to sign in with.');

            return self::FAILURE;
        }

        $counts = $this->counts();
        $others = User::whereKeyNot($admin->id)->count();
        $audit = $this->option('keep-audit') ? 0 : AuditLog::count();

        $this->table(
            ['What', 'Rows'],
            collect($counts)->filter()->map(fn ($rows, $table) => [$table, $rows])
                ->push(['users (all but '.$admin->username.')', $others])
                ->push(['audit_logs', $this->option('keep-audit') ? 'kept' : $audit])
                ->all(),
        );

        $this->newLine();
        $this->line('Keeping: '.$admin->username.', company settings, leave types, shifts, holidays.');

        if (! $this->option('force')) {
            $this->warn('Nothing deleted. Re-run with --force to go ahead.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($admin, $audit) {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Every login but the administrator's, and their tokens and
            // sessions with them — an account deleted while its session lives
            // is a session nobody owns.
            User::whereKeyNot($admin->id)->get()->each(function (User $user) {
                $user->tokens()->delete();
                $user->forceDelete();
            });

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', '!=', $admin->id)->delete();
            }

            if (! $this->option('keep-audit')) {
                AuditLog::query()->delete();

                /*
                 * One row survives the clearing, and it is the clearing
                 * itself. An audit trail that goes silent about the moment it
                 * was emptied is the one event it most needs to record — and
                 * this row is signed like every other, so it can be checked.
                 */
                AuditLog::create([
                    'user_id' => $admin->id,
                    'auditable_type' => User::class,
                    'auditable_id' => null,
                    'event' => 'demo_data_reset',
                    'new_values' => [
                        'cleared_audit_rows' => $audit,
                        'kept' => 'admin login, settings, leave types, shifts, holidays',
                        'by' => 'artisan hris:reset-demo-data',
                    ],
                ]);
            }
        });

        // The settings map is cached forever; the rows behind it are gone.
        Setting::flushCache();

        $this->newLine();
        $this->info('Done. The system is empty apart from '.$admin->username.', the settings and the master data.');
        $this->line('Sign in and start with Employee Information → New Hires or Add Employee.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
