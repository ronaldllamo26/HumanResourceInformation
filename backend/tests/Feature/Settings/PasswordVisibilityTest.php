<?php

namespace Tests\Feature\Settings;

use App\Models\AccountChangeRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_staff_passwords_and_change_requests(): void
    {
        $superAdmin = User::factory()->create([
            'name' => 'Alice SuperAdmin',
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $staff = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Bob Staff',
            'username' => 'bob.staff@primepower.com',
            'password' => Hash::make('StaffSecret123!'),
            'visible_password' => Crypt::encryptString('StaffSecret123!'),
        ]);

        $this->actingAs($superAdmin)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users')
                ->where('is_super_admin', true)
                ->where('can_manage_requests', true)
                ->where('can_view_passwords', true)
                ->has('users', fn (Assert $users) => $users
                    ->where('1.id', $staff->id)
                    ->where('1.password_plain', 'StaffSecret123!')
                    ->etc(),
                ),
            );
    }

    public function test_regular_admin_can_manage_change_requests_but_cannot_view_passwords(): void
    {
        $admin = User::factory()->create([
            'name' => 'Alice Admin',
            'role' => User::ROLE_ADMIN,
        ]);

        $staff = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'name' => 'Bob Staff',
            'username' => 'bob.staff@primepower.com',
            'password' => Hash::make('StaffSecret123!'),
            'visible_password' => Crypt::encryptString('StaffSecret123!'),
        ]);

        AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_username' => 'bob.new@primepower.com',
            'staff_notes' => 'Need to update email',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users')
                ->where('is_super_admin', false)
                ->where('can_manage_requests', true)
                ->where('can_view_passwords', false)
                ->has('change_requests', 1)
                ->has('users', fn (Assert $users) => $users
                    ->where('1.id', $staff->id)
                    ->where('1.password_plain', null)
                    ->etc(),
                ),
            );
    }

    public function test_user_model_encrypts_and_decrypts_visible_password(): void
    {
        $user = User::factory()->create();
        $user->setVisiblePassword('MyP@ssw0rd2026!');
        $user->save();

        $user->refresh();

        $this->assertNotEquals('MyP@ssw0rd2026!', $user->getRawOriginal('visible_password'));
        $this->assertEquals('MyP@ssw0rd2026!', $user->getDecryptedPassword());
    }

    public function test_user_update_password_syncs_visible_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->put('/settings/security/password', [
                'current_password' => 'OldPassword123!',
                'password' => 'NewSecur3Password!',
                'password_confirmation' => 'NewSecur3Password!',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecur3Password!', $user->password));
        $this->assertEquals('NewSecur3Password!', $user->getDecryptedPassword());
    }

    public function test_admin_resetting_password_updates_visible_password_without_disclosing_in_flash(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $staff = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $response = $this->actingAs($admin)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->post("/settings/users/{$staff->id}/reset-password");

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $flashSuccess = session('success');

        // Admin should NOT see the temporary password in flash message
        $this->assertStringNotContainsString('New password for', $flashSuccess);
        $this->assertStringContainsString('Password has been reset for', $flashSuccess);

        $staff->refresh();
        $this->assertNotNull($staff->visible_password);
        $this->assertNotNull($staff->getDecryptedPassword());
    }

    public function test_super_admin_resetting_password_updates_visible_password_and_discloses_in_flash(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $staff = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $response = $this->actingAs($superAdmin)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->post("/settings/users/{$staff->id}/reset-password");

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $flashSuccess = session('success');

        // Super Admin DOES see the temporary password in flash message
        $this->assertStringContainsString('New password for', $flashSuccess);

        $staff->refresh();
        $this->assertNotNull($staff->visible_password);
        $this->assertNotNull($staff->getDecryptedPassword());
        $this->assertStringContainsString($staff->getDecryptedPassword(), $flashSuccess);
    }
}
