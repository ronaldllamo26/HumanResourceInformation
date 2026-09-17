<?php

namespace Tests\Feature\Settings;

use App\Models\EmployeeEndorsement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A password somebody else chose has to be replaced before the account works.
 *
 * Three paths hand a person a password: the seeder, HR creating a login from
 * the employee form, and an admin resetting one. All three deliver it through
 * a channel that keeps a copy, so the password is known to two people from the
 * moment it exists. RequirePasswordChange is what makes that temporary.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    // --- The hold ----------------------------------------------------------

    public function test_a_flagged_account_cannot_reach_the_rest_of_the_system(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'must_change_password' => true,
        ]);

        foreach (['/dashboard', '/hr/employees', '/settings/general'] as $path) {
            $this->actingAs($user)
                ->get($path)
                ->assertRedirect(route('settings.security'));
        }
    }

    public function test_it_can_still_reach_the_screen_that_lifts_the_hold(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/settings/security')->assertOk();
    }

    public function test_an_account_with_both_otp_and_forced_password_change_can_reach_otp_challenge(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'otp_email' => 'employee@gmail.com',
        ]);

        // Has not solved OTP yet: accessing a route should redirect to OTP, NOT enter an infinite loop
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertRedirect(route('otp.challenge'));

        // Accessing OTP screen directly should return 200 OK, not be redirected back to settings.security
        $this->actingAs($user)->get(route('otp.challenge'))->assertOk();
    }


    /**
     * Trapping someone in a session they cannot leave is worse than the risk
     * being managed — signing out reduces exposure rather than adding to it.
     */
    public function test_it_can_still_sign_out(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_an_ordinary_account_is_untouched(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    /**
     * The API stack is deliberately outside this. A Sanctum token is a machine
     * credential on a biometric device, and there is nobody at the other end
     * of it to type a new password — holding it would take the timeclock down
     * rather than secure it.
     */
    public function test_an_api_token_is_not_held(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_HR_STAFF,
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/employees')->assertOk();
    }

    // --- Lifting it --------------------------------------------------------

    public function test_changing_the_password_lifts_the_hold(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => 'Temporary-Pass1!',
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->put('/settings/security/password', [
                'current_password' => 'Temporary-Pass1!',
                'password' => 'A-password-of-my-own-1!',
                'password_confirmation' => 'A-password-of-my-own-1!',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    /**
     * A token issued while the shared password was live was issued to whoever
     * held that password. Rotating one and leaving the other is half a
     * rotation.
     */
    public function test_lifting_the_hold_revokes_tokens_issued_under_the_old_password(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => 'Temporary-Pass1!',
            'must_change_password' => true,
        ]);

        $user->createToken('biometric-device');

        $this->actingAs($user)->put('/settings/security/password', [
            'current_password' => 'Temporary-Pass1!',
            'password' => 'A-password-of-my-own-1!',
            'password_confirmation' => 'A-password-of-my-own-1!',
        ]);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    /** An ordinary password change is not a rotation, and keeps its tokens. */
    public function test_an_ordinary_password_change_keeps_its_tokens(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => 'My-own-password-1!',
            'must_change_password' => false,
        ]);

        $user->createToken('biometric-device');

        $this->actingAs($user)->put('/settings/security/password', [
            'current_password' => 'My-own-password-1!',
            'password' => 'My-next-password-1!',
            'password_confirmation' => 'My-next-password-1!',
        ]);

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    // --- Where the flag is set ---------------------------------------------

    public function test_an_account_created_in_users_and_access_is_flagged(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/settings/users', [
            'name' => 'New Person',
            'username' => 'new.person',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->assertTrue(
            User::where('username', 'new.person@primepower.com')->first()->must_change_password,
        );
    }

    public function test_a_reset_password_is_flagged(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($admin)->post("/settings/users/{$user->id}/reset-password");

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_a_login_provisioned_from_the_employee_form_is_flagged(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/hr/employees', [
            // The form is reached by approving a Core 1 endorsement; there is
            // no other way to create an employee.
            'endorsement_id' => EmployeeEndorsement::factory()->create()->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'nationality' => 'Filipino',
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-15',
            'basic_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'status' => 'active',
            'email' => 'provisioned@primepower.com',
            'create_user_account' => true,
            'user_role' => User::ROLE_EMPLOYEE,
        ]);

        $created = User::where('username', 'jdelacruz@primepower.com')->first();

        $this->assertNotNull($created, 'No login was provisioned.');
        $this->assertTrue($created->must_change_password);
    }
}
