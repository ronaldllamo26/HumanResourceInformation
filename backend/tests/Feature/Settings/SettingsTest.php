<?php

namespace Tests\Feature\Settings;

use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountProvisioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    // --- Access -----------------------------------------------------------

    public function test_the_settings_root_lands_on_the_section_menu(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
    }

    /**
     * `/settings` is a menu now, and the reason is worth keeping written down.
     *
     * It used to redirect: first always to General, which is admin-only, so
     * every other role was sent into a 403 by a door shown to everybody; then
     * to General for an admin and Appearance for everybody else, which fixed
     * the 403 and left the root with no page of its own. Neither answered the
     * question a nav entry raises — a sidebar link that silently lands you on
     * the company's regional formats has chosen a section on your behalf.
     *
     * So the assertion that matters is that every role reaches the same menu,
     * rather than that each role reaches a different room.
     */
    public function test_every_role_reaches_the_settings_menu(): void
    {
        foreach ([User::ROLE_HR_STAFF, User::ROLE_SUPERVISOR, User::ROLE_EMPLOYEE] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/settings')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
        }

        // And a section the menu offers them really is open — a menu row into
        // a 403 would be the same bug one hop further along.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/settings/appearance')
            ->assertOk();
    }

    public function test_the_old_profile_url_now_points_at_security(): void
    {
        $this->actingAs($this->admin())->get('/profile')->assertRedirect('/settings/security');
    }

    public function test_company_settings_are_administrator_only(): void
    {
        $hr = User::factory()->hrStaff()->create();

        $this->actingAs($hr)->get('/settings/general')->assertForbidden();
        $this->actingAs($hr)->get('/settings/users')->assertForbidden();
        $this->actingAs($hr)->get('/settings/integrations')->assertForbidden();
    }

    public function test_everyone_reaches_their_own_appearance_and_security(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/appearance')->assertOk();
        $this->actingAs($user)->get('/settings/security')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/general')->assertRedirect('/login');
    }

    // --- General ----------------------------------------------------------

    public function test_company_settings_are_saved_and_read_back(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/general', [
                'company' => [
                    'name' => 'PrimePower Manpower Inc.',
                    'tin' => '123-456-789-000',
                ],
                'regional' => [
                    'timezone' => 'Asia/Manila',
                    'date_format' => 'Y-m-d',
                    'currency' => 'PHP',
                    'week_starts_on' => 1,
                ],
            ])
            ->assertRedirect();

        $this->assertSame('PrimePower Manpower Inc.', Setting::get('company.name'));
        $this->assertSame('Y-m-d', Setting::get('regional.date_format'));
    }

    public function test_unset_settings_fall_back_to_their_defaults(): void
    {
        $this->assertSame(
            Setting::DEFAULTS['company.name'],
            Setting::get('company.name'),
        );
        $this->assertSame(30, Setting::get('notifications.expiry_lead_days'));
    }

    public function test_a_company_name_is_required(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/general', [
                'company' => ['name' => ''],
                'regional' => [
                    'timezone' => 'Asia/Manila',
                    'date_format' => 'Y-m-d',
                    'currency' => 'PHP',
                    'week_starts_on' => 1,
                ],
            ])
            ->assertSessionHasErrors('company.name');
    }

    // --- Users & Access ---------------------------------------------------

    public function test_an_admin_can_create_an_account(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/users', [
                'name' => 'Nina Cruz',
                'username' => 'nina',
                'role' => User::ROLE_HR_STAFF,
            ])
            ->assertRedirect()
            // Handed over once, in the flash message: the username they sign
            // in with, and the temporary password to go with it.
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Username: nina@primepower.com')
                && str_contains($message, 'temporary password'));

        // Typed without the domain, stored with it.
        $this->assertDatabaseHas('users', ['username' => 'nina@primepower.com', 'email' => null, 'role' => 'hr_staff']);
    }

    public function test_an_admin_can_create_an_account_with_gmail_and_credentials_are_emailed(): void
    {
        Notification::fake();

        $this->actingAs($this->admin())
            ->post('/settings/users', [
                'name' => 'Maria Santos',
                'username' => 'mariasantos',
                'otp_email' => 'maria.santos@gmail.com',
                'role' => User::ROLE_SUPERVISOR,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Username: mariasantos@primepower.com')
                && str_contains($message, 'maria.santos@gmail.com'));

        $this->assertDatabaseHas('users', [
            'name' => 'Maria Santos',
            'username' => 'mariasantos@primepower.com',
            'otp_email' => 'maria.santos@gmail.com',
            'role' => User::ROLE_SUPERVISOR,
            'must_change_password' => true,
        ]);

        $created = User::where('username', 'mariasantos@primepower.com')->firstOrFail();

        Notification::assertSentTo(
            $created,
            AccountProvisioned::class,
            function (AccountProvisioned $notification) use ($created) {
                $mail = $notification->toMail($created);

                $this->assertSame('Your PrimePower HRIS Account Credentials', $mail->subject);
                $this->assertSame(User::ROLE_SUPERVISOR, $notification->role);
                $this->assertNotEmpty($notification->temporaryPassword);

                return true;
            }
        );
    }

    public function test_a_blank_username_is_made_from_the_name(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/users', ['name' => 'Nina Cruz', 'role' => User::ROLE_EMPLOYEE])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['name' => 'Nina Cruz', 'username' => 'ninacruz@primepower.com']);
    }

    public function test_a_username_must_be_free_and_well_formed(): void
    {
        User::factory()->create(['username' => 'taken@primepower.com']);

        $this->actingAs($this->admin())
            ->post('/settings/users', ['name' => 'A', 'username' => 'taken', 'role' => User::ROLE_EMPLOYEE])
            ->assertSessionHasErrors('username');

        $this->actingAs($this->admin())
            ->post('/settings/users', ['name' => 'A', 'username' => 'Has Spaces', 'role' => User::ROLE_EMPLOYEE])
            ->assertSessionHasErrors('username');
    }

    public function test_creating_an_account_can_link_an_employee(): void
    {
        $employee = Employee::factory()->create(['user_id' => null, 'email' => 'juan@primepower.com']);

        $this->actingAs($this->admin())->post('/settings/users', [
            'employee_id' => $employee->id,
            'name' => 'Juan Dela Cruz',
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->assertNotNull($employee->fresh()->user_id);
    }

    public function test_an_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/settings/users/{$admin->id}/role", ['role' => User::ROLE_EMPLOYEE])
            ->assertSessionHas('error');

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/settings/users/{$admin->id}/toggle")
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_deactivating_an_account_revokes_its_api_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('device');

        $this->actingAs($this->admin())
            ->post("/settings/users/{$user->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // --- Security ---------------------------------------------------------

    public function test_a_user_can_change_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/settings/security/password', [
                'current_password' => 'password',
                'password' => 'Much-L0nger-Secret',
                'password_confirmation' => 'Much-L0nger-Secret',
            ])
            ->assertRedirect();

        $this->assertTrue(
            Hash::check('Much-L0nger-Secret', $user->fresh()->password),
        );
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->put('/settings/security/password', [
                'current_password' => 'not-my-password',
                'password' => 'Much-L0nger-Secret',
                'password_confirmation' => 'Much-L0nger-Secret',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_changing_the_email_clears_its_verification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/security/profile', [
            'name' => $user->name,
            'email' => 'moved@primepower.com',
        ]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_last_administrator_cannot_delete_their_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete('/settings/security/account', ['password' => 'password'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /**
     * The log is its own screen under Administration now; Security keeps only
     * the door to it, and a non-HR role is offered neither.
     */
    public function test_the_audit_log_is_hidden_from_non_hr_roles(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)
            ->get('/settings/security')
            ->assertInertia(fn (Assert $page) => $page->where('canViewAudit', false));

        $this->actingAs($employee)->get('/settings/audit-logs')->assertForbidden();
    }

    // --- Integrations -----------------------------------------------------

    public function test_an_admin_can_issue_and_revoke_an_api_token(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/settings/integrations/tokens', ['name' => 'Biometric device'])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'copy it now'));

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = $admin->tokens()->firstOrFail();

        $this->actingAs($admin)
            ->delete("/settings/integrations/tokens/{$token->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // --- Data -------------------------------------------------------------

    public function test_the_employee_export_streams_csv(): void
    {
        Employee::factory()->create(['first_name' => 'Elena', 'last_name' => 'Marquez']);

        $response = $this->actingAs($this->admin())
            ->get('/settings/data/export/employees')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('Elena', $response->streamedContent());
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
