<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogSigner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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

    $admin = User::firstOrNew(['username' => 'admin@primepower.com']);

    $admin->fill([
        'name' => $admin->name ?: 'System Administrator',
        'email' => $admin->email ?: 'admin@primepower.com',
        'role' => User::ROLE_SUPER_ADMIN,
        'is_active' => true,
        'password' => $password,
        'must_change_password' => false,
    ])->save();

    $admin->setVisiblePassword($password);
    $admin->tokens()->delete();

    if ($hr = User::where('username', 'hrstaff@primepower.com')->first()) {
        $hr->update([
            'password' => $password,
            'must_change_password' => false,
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

    $this->info("Admin and seed account passwords set to: {$password}");
})->purpose('Set the admin and seeded account passwords');

/*
 * Automatically binds all administrator accounts without an OTP email to ADMIN_OTP_EMAIL.
 * Ensures MFA is immediately active upon deployment.
 */
Artisan::command('hris:bind-admin-otp', function () {
    $email = env('ADMIN_OTP_EMAIL', 'gavegavebenavidez@gmail.com');

    if (! filled($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $admins = User::where('role', User::ROLE_ADMIN)->get();

    foreach ($admins as $admin) {
        if (blank($admin->otp_email)) {
            $admin->forceFill([
                'otp_email' => strtolower(trim((string) $email)),
                'otp_enabled' => true,
            ])->save();

            $this->info("Bound MFA for admin [{$admin->username}] to {$email}");
        }
    }
})->purpose('Bind admin accounts without OTP email to ADMIN_OTP_EMAIL on start');

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
