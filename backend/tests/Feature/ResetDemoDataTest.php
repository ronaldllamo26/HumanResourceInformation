<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emptying the demonstration data.
 *
 * The command had no test at all, which is how the bug in the first test
 * below survived a role being added to the system: it refused to run on the
 * real database, and the only way anybody found out was by running it.
 */
class ResetDemoDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A system whose only administrator is a *super* administrator runs.
     *
     * The guard looked for `ROLE_ADMIN` alone, which was the whole set of
     * administrators when it was written. `super_admin` arrived afterwards
     * and the seeded `admin@primepower.com` was promoted into it, leaving no
     * `admin` row on the real database — so the command refused to run on a
     * system that had an administrator the whole time. Right in substance
     * (never leave a database nobody can sign in to), wrong in fact, and the
     * more dangerous of the two: a refusal nobody can explain is one somebody
     * works around with `migrate:fresh`.
     */
    public function test_a_super_administrator_alone_is_enough_to_run(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'username' => 'admin@primepower.com',
        ]);
        Employee::factory()->count(3)->create();

        $this->artisan('hris:reset-demo-data --force')->assertSuccessful();

        $this->assertSame(0, Employee::withTrashed()->count());
        $this->assertSame(1, User::count());
        $this->assertTrue(User::sole()->is($admin));
    }

    /**
     * With both, the super administrator is the one kept.
     *
     * It is the higher authority, and the account that must not turn out to
     * be the one deleted.
     */
    public function test_the_super_administrator_is_kept_over_a_plain_administrator(): void
    {
        // Created first, so an `orderBy('id')` alone would have picked it.
        $plain = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->artisan('hris:reset-demo-data --force')->assertSuccessful();

        $this->assertTrue(User::sole()->is($super));
        $this->assertNull(User::find($plain->id));
    }

    /** A database with nobody who could sign in afterwards is refused. */
    public function test_it_refuses_when_there_is_no_administrator_of_either_kind(): void
    {
        User::factory()->create(['role' => User::ROLE_HR_STAFF]);
        Employee::factory()->create();

        $this->artisan('hris:reset-demo-data --force')->assertFailed();

        // And nothing was deleted on the way to refusing.
        $this->assertSame(1, Employee::withTrashed()->count());
    }

    /**
     * Without `--force` it prints and deletes nothing.
     *
     * A command that empties a payroll system on a typo is a command nobody
     * should have written.
     */
    public function test_a_dry_run_deletes_nothing(): void
    {
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Employee::factory()->count(2)->create();

        $this->artisan('hris:reset-demo-data')->assertSuccessful();

        $this->assertSame(2, Employee::withTrashed()->count());
    }

    /**
     * What survives is short and deliberate: the login, the settings, and the
     * master data nothing can be keyed without.
     */
    public function test_the_master_data_and_settings_survive(): void
    {
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        LeaveType::factory()->count(2)->create();
        Setting::setMany(['company.name' => 'PrimePower']);
        // No factory on this model, so it is created directly.
        Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->artisan('hris:reset-demo-data --force')->assertSuccessful();

        $this->assertSame(2, LeaveType::count(), 'Leave types are master data, not demonstration data.');
        $this->assertSame('PrimePower', Setting::get('company.name'));
        // Departments are seeded examples rather than this company's, so they go.
        $this->assertSame(0, Department::count());
    }

    /**
     * The clearing records itself, and that row is the only one left.
     *
     * An audit trail that goes silent about the moment it was emptied is
     * missing the one event it most needs to hold.
     */
    public function test_the_clearing_is_the_one_audit_row_that_survives(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Employee::factory()->count(2)->create(); // writes `created` rows

        $this->assertGreaterThan(0, AuditLog::count());

        $this->artisan('hris:reset-demo-data --force')->assertSuccessful();

        $row = AuditLog::sole();
        $this->assertSame('demo_data_reset', $row->event);
        $this->assertSame($admin->id, $row->user_id);
        // Signed like every other row, so it can be checked afterwards.
        $this->assertNotNull($row->signature);
    }

    public function test_keep_audit_leaves_the_trail_alone(): void
    {
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        Employee::factory()->count(2)->create();

        $before = AuditLog::count();

        $this->artisan('hris:reset-demo-data --force --keep-audit')->assertSuccessful();

        $this->assertGreaterThanOrEqual($before, AuditLog::count());
    }
}
