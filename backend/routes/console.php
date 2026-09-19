<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogSigner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Seeds a database that has never been seeded, and does nothing to one that has.
 *
 * A container host with no terminal cannot run `db:seed` by hand, so the first
 * deploy would otherwise come up with no accounts and nobody able to sign in.
 * Re-seeding on every restart is not the answer either: outside `local` the
 * seeder issues fresh passwords, so each restart would lock everybody out of
 * the passwords they had chosen. An existing login is the signal to stop.
 */
Artisan::command('hris:seed-if-empty', function () {
    if (Employee::query()->exists()) {
        $this->info('Employee records already exist — not seeding.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed the database only if it has no employee records yet');

/*
 * Sets the admin password from HRIS_ADMIN_PASSWORD — once per value.
 *
 * Remembering which value was applied (as a hash, never the password) is what
 * stops a restart from undoing the password the admin chose afterwards: the
 * same variable left in the panel is recognised and skipped, and only a new
 * value is applied again. Creates the admin login if the database has none.
 */
Artisan::command('hris:set-admin-password', function () {
    $password = (string) (config('auth.bootstrap_admin_password') ?: env('HRIS_ADMIN_PASSWORD', 'Password123!'));

    if (mb_strlen($password) < 8) {
        $this->error('HRIS_ADMIN_PASSWORD must be at least 8 characters — nothing was changed.');

        return;
    }

    $otpEmail = env('ADMIN_OTP_EMAIL', 'johnpogs.b@gmail.com');

    $admin = User::firstOrNew(['username' => 'admin@primepower.com']);

    $admin->fill([
        'name' => $admin->name ?: 'System Administrator',
        'email' => $admin->email ?: 'admin@primepower.com',
        'role' => User::ROLE_SUPER_ADMIN,
        'is_active' => true,
        'password' => $password,
        'must_change_password' => false,
        'otp_email' => $otpEmail,
        'otp_enabled' => true,
    ])->save();

    $admin->setVisiblePassword($password);
    $admin->tokens()->delete();

    if ($hr = User::where('username', 'hrstaff@primepower.com')->first()) {
        $hr->update([
            'password' => $password,
            'must_change_password' => false,
            'otp_email' => $otpEmail,
            'otp_enabled' => true,
        ]);
        $hr->setVisiblePassword($password);
    }

    if ($emp = User::where('username', 'employee@primepower.com')->first()) {
        $emp->update([
            'password' => $password,
            'must_change_password' => false,
        ]);
        $emp->setVisiblePassword($password);
    }

    $this->info("Admin and seed account passwords set to: {$password}, OTP bound to {$otpEmail}");
})->purpose('Set the admin and seeded account passwords');

/*
 * Automatically binds all administrator accounts without an OTP email to ADMIN_OTP_EMAIL.
 * Ensures MFA is immediately active upon deployment.
 */
Artisan::command('hris:bind-admin-otp', function () {
    $email = env('ADMIN_OTP_EMAIL', 'johnpogs.b@gmail.com');

    if (! filled($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $admins = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_HR_STAFF])->get();

    foreach ($admins as $admin) {
        $admin->forceFill([
            'otp_email' => strtolower(trim((string) $email)),
            'otp_enabled' => true,
        ])->save();

        $this->info("Bound MFA for admin [{$admin->username}] to {$email}");
    }
})->purpose('Bind admin accounts without OTP email to ADMIN_OTP_EMAIL on start');

/*
 * Trims existing demo data down to 2 clean sample employees (Juan Dela Cruz and Maria Clara).
 * Automatically cascades and deletes excess child records across all modules.
 */
Artisan::command('hris:trim-to-two-employees', function () {
    $employees = Employee::orderBy('id')->get();

    if ($employees->count() <= 2) {
        $this->info('Already 2 or fewer employees.');

        return;
    }

    $keep = $employees->take(2);
    $keepIds = $keep->pluck('id')->all();
    $removeEmployees = Employee::whereNotIn('id', $keepIds)->get();

    $first = $keep->first();
    if ($first) {
        $first->update([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'status' => 'active',
        ]);
        if ($first->user) {
            $first->user->update([
                'name' => 'Juan Dela Cruz',
                'username' => 'jdelacruz@primepower.com',
                'role' => User::ROLE_SUPERVISOR,
                'password' => 'Password123!',
                'must_change_password' => false,
            ]);
        }
    }

    $second = $keep->skip(1)->first();
    if ($second) {
        $second->update([
            'first_name' => 'Maria',
            'last_name' => 'Clara',
            'status' => 'active',
            'supervisor_id' => $first?->id,
        ]);
        if ($second->user) {
            $second->user->update([
                'name' => 'Maria Clara',
                'username' => 'mclara@primepower.com',
                'role' => User::ROLE_EMPLOYEE,
                'password' => 'Password123!',
                'must_change_password' => false,
            ]);
        }
    }

    foreach ($removeEmployees as $emp) {
        if ($emp->user_id) {
            User::where('id', $emp->user_id)
                ->whereNotIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_HR_STAFF])
                ->delete();
        }

        if (Schema::hasTable('leave_requests')) {
            DB::table('leave_requests')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('leave_balances')) {
            DB::table('leave_balances')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('attendance_logs')) {
            DB::table('attendance_logs')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_shifts')) {
            DB::table('employee_shifts')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('overtime_requests')) {
            DB::table('overtime_requests')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('time_corrections')) {
            DB::table('time_corrections')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('payslip_lines')) {
            DB::table('payslip_lines')->whereIn('payslip_id', function ($q) use ($emp) {
                $q->select('id')->from('payslips')->where('employee_id', $emp->id);
            })->delete();
        }
        if (Schema::hasTable('payslips')) {
            DB::table('payslips')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_loans')) {
            DB::table('employee_loans')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_allowances')) {
            DB::table('employee_allowances')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('performance_review_ratings')) {
            DB::table('performance_review_ratings')->whereIn('performance_review_id', function ($q) use ($emp) {
                $q->select('id')->from('performance_reviews')->where('employee_id', $emp->id);
            })->delete();
        }
        if (Schema::hasTable('performance_reviews')) {
            DB::table('performance_reviews')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_documents')) {
            DB::table('employee_documents')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('document_scans')) {
            DB::table('document_scans')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_education')) {
            DB::table('employee_education')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_trainings')) {
            DB::table('employee_trainings')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_skills')) {
            DB::table('employee_skills')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_license_verifications')) {
            DB::table('employee_license_verifications')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('disciplinary_actions')) {
            DB::table('disciplinary_actions')->where('employee_id', $emp->id)->delete();
        }
        if (Schema::hasTable('employee_endorsements')) {
            DB::table('employee_endorsements')->where('employee_id', $emp->id)->delete();
        }

        Employee::where('supervisor_id', $emp->id)->update(['supervisor_id' => null]);
        Department::where('head_employee_id', $emp->id)->update(['head_employee_id' => null]);

        $emp->delete();
    }

    if (Schema::hasTable('clients')) {
        $keepClients = DB::table('clients')->orderBy('id')->limit(2)->pluck('id');
        DB::table('clients')->whereNotIn('id', $keepClients)->delete();
    }

    $this->info('Successfully trimmed to 2 sample employees: Juan Dela Cruz and Maria Clara.');
})->purpose('Trim database records to 2 clean sample employees');

/*
 * Checks every audit row against its signature. Run it before handing the
 * log to an auditor, or on a schedule; the same check is a button on
 * Settings > Security.
 */
Artisan::command('audit:verify', function (AuditLogSigner $signer) {
    $result = $signer->verify();

    $this->line("Checked {$result['checked']} audit row(s): {$result['valid']} valid, "
        .count($result['altered'])." altered, {$result['unsigned']} unsigned, {$result['gaps']} missing id(s).");

    if ($result['altered'] !== []) {
        $this->error('Altered rows (ids): '.implode(', ', $result['altered']));

        return 1;
    }

    $this->info('No altered audit rows.');

    return 0;
})->purpose('Verify the audit log has not been altered');

/*
 * Automated background scheduler tasks (Sections 1, 5, & 6 compliance).
 */
use Illuminate\Support\Facades\Schedule;

Schedule::command('salaries:apply-due')->dailyAt('00:01');
Schedule::command('audit:verify')->dailyAt('02:00');
Schedule::command('hris:backup')->dailyAt('03:00');
Schedule::command('hris:send-scheduled-reports')->weeklyOn(1, '08:00');
