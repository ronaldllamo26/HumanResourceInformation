<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_happens_without_the_variable(): void
    {
        config(['auth.bootstrap_admin_password' => null]);
        $admin = User::factory()->admin()->create(['username' => 'admin@primepower.com']);
        $before = $admin->password;

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $this->assertSame($before, $admin->fresh()->password);
    }

    public function test_the_variable_sets_the_admin_password_and_forces_a_change(): void
    {
        config(['auth.bootstrap_admin_password' => 'Temp-Pass-2026']);
        $admin = User::factory()->admin()->create(['username' => 'admin@primepower.com']);
        $admin->createToken('old');

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check('Temp-Pass-2026', $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_the_admin_login_is_created_when_the_database_has_none(): void
    {
        config(['auth.bootstrap_admin_password' => 'Temp-Pass-2026']);

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $admin = User::where('username', 'admin@primepower.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());

        $this->post('/login', ['username' => 'admin@primepower.com', 'password' => 'Temp-Pass-2026']);
        $this->assertAuthenticatedAs($admin);
    }

    /** A restart with the variable still set must not undo the password the admin chose. */
    public function test_the_same_value_is_applied_only_once(): void
    {
        config(['auth.bootstrap_admin_password' => 'Temp-Pass-2026']);
        User::factory()->admin()->create(['username' => 'admin@primepower.com']);

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $admin = User::where('username', 'admin@primepower.com')->first();
        $admin->update(['password' => 'Chosen-By-Admin-9', 'must_change_password' => false]);

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $this->assertTrue(Hash::check('Chosen-By-Admin-9', $admin->fresh()->password));
    }

    public function test_a_new_value_is_applied_again(): void
    {
        User::factory()->admin()->create(['username' => 'admin@primepower.com']);

        config(['auth.bootstrap_admin_password' => 'First-Pass-2026']);
        $this->artisan('hris:set-admin-password')->assertSuccessful();

        config(['auth.bootstrap_admin_password' => 'Second-Pass-2026']);
        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $admin = User::where('username', 'admin@primepower.com')->first();
        $this->assertTrue(Hash::check('Second-Pass-2026', $admin->password));
    }

    public function test_a_too_short_value_changes_nothing(): void
    {
        config(['auth.bootstrap_admin_password' => 'short']);
        $admin = User::factory()->admin()->create(['username' => 'admin@primepower.com']);
        $before = $admin->password;

        $this->artisan('hris:set-admin-password')->assertSuccessful();

        $this->assertSame($before, $admin->fresh()->password);
    }
}
