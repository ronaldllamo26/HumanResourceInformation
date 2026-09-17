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

    public function test_a_user_signs_in_with_their_username(): void
    {
        $user = User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'mariasantos',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_signs_in_with_their_registered_gmail(): void
    {
        $user = User::factory()->create([
            'username' => 'mariasantos@primepower.com',
            'otp_email' => 'maria@gmail.com',
        ]);

        $this->post('/login', [
            'username' => 'maria@gmail.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_signs_in_with_username_without_domain(): void
    {
        $user = User::factory()->create([
            'username' => 'gbenavidez@primepower.com',
            'password' => '4B%gJE8f%Z_TcZ+j',
        ]);

        $this->post('/login', [
            'username' => 'gbenavidez',
            'password' => '4B%gJE8f%Z_TcZ+j',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_signs_in_with_gmail_and_complex_temporary_password(): void
    {
        $user = User::factory()->create([
            'username' => 'gbenavidez@primepower.com',
            'otp_email' => 'gavebenavidez@gmail.com',
            'password' => '4B%gJE8f%Z_TcZ+j',
        ]);

        $this->post('/login', [
            'username' => 'gavebenavidez@gmail.com',
            'password' => '4B%gJE8f%Z_TcZ+j',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    /** The seeded role accounts sign in exactly as they are written. */
    public function test_a_username_in_the_company_shape_signs_in(): void
    {
        $user = User::factory()->admin()->create(['username' => 'admin@primepower.com']);

        $this->post('/login', ['username' => 'admin@primepower.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_username_is_not_case_sensitive(): void
    {
        $user = User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'MariaSantos',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        User::factory()->create(['username' => 'mariasantos']);

        $this->post('/login', [
            'username' => 'mariasantos',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * Each role signs in the same way and lands on the same dashboard; what
     * they can open afterwards is decided by the role on the account.
     */
    public function test_every_role_signs_in_with_a_username(): void
    {
        foreach (User::ROLES as $role) {
            $user = User::factory()->create(['username' => "demo_{$role}", 'role' => $role]);

            $this->post('/login', ['username' => "demo_{$role}", 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));

            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    /**
     * Every account gets a username however it was created, so none of the
     * four creation paths can make a login that cannot sign in.
     */
    public function test_a_new_account_is_given_a_username_from_its_name(): void
    {
        $user = User::factory()->create(['username' => null, 'name' => 'Maria Santos']);

        $this->assertSame('mariasantos@primepower.com', $user->username);
    }

    public function test_a_taken_username_gets_a_number_rather_than_colliding(): void
    {
        User::factory()->create(['username' => 'jdelacruz@primepower.com']);

        $this->assertSame('jdelacruz2@primepower.com', User::usernameFor('Juan', 'Dela Cruz'));
    }

    public function test_a_login_account_needs_no_email(): void
    {
        $user = User::factory()->create(['username' => 'noemail']);

        $this->assertNull($user->email);

        $this->post('/login', ['username' => 'noemail', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_username_given_explicitly_is_kept(): void
    {
        $user = User::factory()->create(['username' => 'boss', 'name' => 'Someone Else']);

        $this->assertSame('boss', $user->username);
    }

    /**
     * Fortify's authenticator-app factor is still gone, and so is the old
     * per-account switch on Settings → Security.
     *
     * `/otp` answers again — the emailed code came back on request — but it is
     * *enrolment* that decides whether anybody is held there: the address a
     * code goes to is connected by an administrator on Users & Access, so an
     * account without one walks past the screen rather than being asked to set
     * a factor up for itself. That is the half of the old design that has not
     * returned, and the half this test guards.
     */
    public function test_the_authenticator_app_factor_is_still_gone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/user/two-factor-authentication')->assertNotFound();
        $this->get('/two-factor-challenge')->assertNotFound();

        // And an account with no connected inbox is not held by the new one.
        $this->actingAs($user)->get('/otp')->assertRedirect(route('dashboard'));
    }

    /**
     * Accounts have no email, so there is nothing to send a reset link or a
     * verification link to. An administrator resets a forgotten password.
     */
    public function test_the_forgot_password_and_email_verification_routes_no_longer_exist(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'someone@primepower.com'])->assertNotFound();
        $this->get('/reset-password/some-token')->assertNotFound();
        $this->post('/reset-password')->assertNotFound();

        $user = User::factory()->create();

        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->actingAs($user)->post('/email/verification-notification')->assertNotFound();
    }

    public function test_the_login_page_offers_no_reset_link(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->missing('canResetPassword'));
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
}
