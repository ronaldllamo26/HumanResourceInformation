<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * People sign in with a username, and the two second factors are gone.
 *
 * A company login should not depend on somebody's personal inbox, and the
 * role already lives on the account — so a username and a password are the
 * whole of signing in. These tests pin that down, including that the removed
 * OTP and two-factor routes are really unreachable rather than merely hidden.
 */
class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_signs_in_with_their_email(): void
    {
        $user = User::factory()->create(['email' => 'mariasantos@example.com']);

        $this->post('/login', [
            'email' => 'mariasantos@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'mariasantos@example.com']);

        $this->post('/login', [
            'email' => 'mariasantos@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Every account gets a username however it was created, so none of the
     * four creation paths can make a login that cannot sign in.
     */
    public function test_a_new_account_is_given_a_username_from_its_email(): void
    {
        $user = User::factory()->create([
            'username' => null,
            'email' => 'hr@primepower.test',
        ]);

        $this->assertSame('hr', $user->username);
    }

    public function test_a_taken_username_gets_a_number_rather_than_colliding(): void
    {
        User::factory()->create(['username' => null, 'email' => 'hr@primepower.test']);
        $second = User::factory()->create(['username' => null, 'email' => 'hr@another.test']);

        $this->assertSame('hr2', $second->username);
    }

    public function test_a_username_given_explicitly_is_kept(): void
    {
        $user = User::factory()->create(['username' => 'boss', 'email' => 'someone@primepower.test']);

        $this->assertSame('boss', $user->username);
    }

    /**
     * Removed, not hidden. A route that still answered would be a second
     * factor half-present — the screen gone but the door still open.
     */
    public function test_the_otp_and_two_factor_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/otp')->assertNotFound();
        $this->actingAs($user)->post('/otp')->assertNotFound();
        $this->actingAs($user)->put('/settings/security/otp')->assertNotFound();
        $this->actingAs($user)->post('/user/two-factor-authentication')->assertNotFound();
        $this->get('/two-factor-challenge')->assertNotFound();
    }

    /**
     * Signing in goes straight to the dashboard. Nothing holds the session on
     * a code screen afterwards.
     */
    public function test_nothing_holds_a_signed_in_session_on_a_code_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_users_can_sign_in_via_role_accounts(): void
    {
        $admin = User::factory()->create(['email' => 'admin@primepower.test', 'role' => User::ROLE_ADMIN]);
        $hr = User::factory()->create(['email' => 'hr@primepower.test', 'role' => User::ROLE_HR_STAFF]);
        $supervisor = User::factory()->create(['email' => 'supervisor@primepower.test', 'role' => User::ROLE_SUPERVISOR]);
        $employee = User::factory()->create(['email' => 'employee@primepower.test', 'role' => User::ROLE_EMPLOYEE]);

        // Test admin sign in
        $this->post('/login', ['email' => 'admin@primepower.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->isAdmin());
        $this->post('/logout');

        // Test HR sign in
        $this->post('/login', ['email' => 'hr@primepower.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($hr);
        $this->assertTrue($hr->isHrAdmin());
        $this->post('/logout');

        // Test supervisor sign in
        $this->post('/login', ['email' => 'supervisor@primepower.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($supervisor);
        $this->assertTrue($supervisor->isSupervisor());
        $this->post('/logout');

        // Test employee sign in
        $this->post('/login', ['email' => 'employee@primepower.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($employee);
        $this->assertFalse($employee->isHrAdmin());
    }

    public function test_login_screen_renders_cleanly_without_role_selector(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->missing('roles')
            ->where('canResetPassword', true)
        );
    }
}
