<?php

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
    if (User::query()->exists()) {
        $this->info('Accounts already exist — not seeding.');

        return;
    }

    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed the database only if it has no accounts yet');

/*
 * Sets the admin password from HRIS_ADMIN_PASSWORD — once per value.
 *
 * Remembering which value was applied (as a hash, never the password) is what
 * stops a restart from undoing the password the admin chose afterwards: the
 * same variable left in the panel is recognised and skipped, and only a new
 * value is applied again. Creates the admin login if the database has none.
 */
Artisan::command('hris:set-admin-password', function () {
    $password = (string) config('auth.bootstrap_admin_password');

    if ($password === '') {
        return;
    }

    if (mb_strlen($password) < 8) {
        $this->error('HRIS_ADMIN_PASSWORD must be at least 8 characters — nothing was changed.');

        return;
    }

    $fingerprint = hash_hmac('sha256', $password, (string) config('app.key'));

    if (Setting::get('security.admin_password_applied') === $fingerprint) {
        $this->info('HRIS_ADMIN_PASSWORD was already applied — remove it from the panel.');

        return;
    }

    $admin = User::firstOrNew(['username' => 'admin@primepower.test']);

    $admin->fill([
        'name' => $admin->name ?: 'System Administrator',
        'role' => User::ROLE_ADMIN,
        'is_active' => true,
        'password' => $password,
        'must_change_password' => true,
    ])->save();

    $admin->tokens()->delete();

    Setting::setMany(['security.admin_password_applied' => $fingerprint], 'security');

    $this->warn('Admin password set for admin@primepower.test from HRIS_ADMIN_PASSWORD. Sign in, change it, then remove the variable.');
})->purpose('Set the admin password from HRIS_ADMIN_PASSWORD (once per value)');

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
