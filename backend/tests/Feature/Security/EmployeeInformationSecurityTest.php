<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The security layer around Employee Information: access de-provisioning,
 * masking of sensitive numbers, the read trail, and the privacy notice.
 */
class EmployeeInformationSecurityTest extends TestCase
{
    use RefreshDatabase;

    // --- Access de-provisioning -------------------------------------------

    public function test_a_deactivated_account_cannot_sign_in_on_the_web(): void
    {
        User::factory()->inactive()->create(['username' => 'gone@primepower.com']);

        $this->post('/login', ['username' => 'gone@primepower.com', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'This account has been deactivated. Contact HR if you think this is a mistake.']);

        $this->assertGuest();
    }

    /** The "deactivated" wording must not tell a guesser the account exists. */
    public function test_a_wrong_password_on_a_deactivated_account_gets_the_ordinary_error(): void
    {
        User::factory()->inactive()->create(['username' => 'gone@primepower.com']);

        $this->post('/login', ['username' => 'gone@primepower.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);
    }

    public function test_a_refused_deactivated_sign_in_is_audited(): void
    {
        $user = User::factory()->inactive()->create(['username' => 'gone@primepower.com']);

        $this->post('/login', ['username' => 'gone@primepower.com', 'password' => 'password']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'login_failed', 'auditable_id' => $user->id]);
    }

    public function test_an_open_session_is_signed_out_once_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivating_deletes_api_tokens_and_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $user->createToken('device');
        \DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
        ]);

        $user->update(['is_active' => false]);

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_a_deactivated_accounts_surviving_token_is_refused(): void
    {
        $user = User::factory()->hrStaff()->create();
        $token = $user->createToken('device')->plainTextToken;

        // Bypass the model hook, as a direct database edit would.
        User::whereKey($user->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function leavingStates(): array
    {
        return [
            'resigned' => [['employment_status' => 'resigned']],
            'terminated' => [['employment_status' => 'terminated']],
            'inactive' => [['status' => 'inactive']],
        ];
    }

    /** @dataProvider leavingStates */
    public function test_leaving_the_company_switches_the_login_off($change): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $employee->update($change);

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_going_on_leave_does_not_switch_the_login_off(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $employee->update(['status' => 'on_leave']);

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_restoring_from_the_archive_switches_the_login_back_on(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($admin)->delete(route('hr.employees.destroy', $employee));
        $this->assertFalse($user->fresh()->is_active);

        app(EmployeeService::class)->restore($employee->fresh());
        $this->assertTrue($user->fresh()->is_active);
    }

    // --- Masking ----------------------------------------------------------

    public function test_the_record_screen_carries_only_the_last_four_characters(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create([
            'sss_number' => '34-1234567-8',
            'tin' => '123-456-789-000',
            'bank_account_number' => '0012345678',
            'drivers_license_number' => 'N02-24-001292',
        ]);

        $response = $this->actingAs($hr)->get(route('hr.employees.show', $employee));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('employee.data.sss_number', '••••••5678')
            ->where('employee.data.tin', '••••••9000')
            ->where('employee.data.bank_account_number', '••••••5678')
            ->where('employee.data.drivers_license_number', '••••••1292')
            ->where('employee.data.numbers_masked', true));

        // Asserted against the whole payload, not the shape of one prop.
        foreach (['34-1234567-8', '341234567', '123-456-789-000', '0012345678', 'N02-24-001292'] as $full) {
            $this->assertStringNotContainsString($full, $response->getContent());
        }
    }

    public function test_the_employee_list_payload_is_masked_too(): void
    {
        $hr = User::factory()->hrStaff()->create();
        Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $this->actingAs($hr)->get(route('hr.employees.index'))
            ->assertDontSee('34-1234567-8', escape: false);
    }

    public function test_the_edit_form_still_receives_the_full_numbers(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $this->actingAs($hr)->get(route('hr.employees.edit', $employee))
            ->assertInertia(fn (Assert $page) => $page->where('employee.data.sss_number', '34-1234567-8'));
    }

    public function test_hr_can_reveal_a_number_and_the_reveal_is_logged(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create(['tin' => '123-456-789-000']);

        $this->actingAs($hr)
            ->postJson(route('hr.employees.reveal', $employee), ['field' => 'tin'])
            ->assertOk()
            ->assertJson(['field' => 'tin', 'value' => '123-456-789-000'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $entry = AuditLog::where('event', 'accessed')->latest('id')->first();
        $this->assertSame($hr->id, $entry->user_id);
        $this->assertSame($employee->id, $entry->auditable_id);
        $this->assertSame('reveal', $entry->new_values['how']);
        $this->assertSame('tin', $entry->new_values['field']);
    }

    public function test_an_employee_can_reveal_their_own_numbers(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id, 'sss_number' => '34-1234567-8']);

        $this->actingAs($user)
            ->postJson(route('hr.employees.reveal', $employee), ['field' => 'sss_number'])
            ->assertOk()
            ->assertJsonPath('value', '34-1234567-8');
    }

    public function test_a_supervisor_cannot_reveal_a_reports_government_number_but_can_see_the_licence(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create([
            'supervisor_id' => $supervisor->id,
            'drivers_license_number' => 'N02-24-001292',
        ]);

        $this->actingAs($supervisorUser)
            ->postJson(route('hr.employees.reveal', $report), ['field' => 'sss_number'])
            ->assertForbidden();

        $this->actingAs($supervisorUser)
            ->postJson(route('hr.employees.reveal', $report), ['field' => 'drivers_license_number'])
            ->assertOk()
            ->assertJsonPath('value', 'N02-24-001292');
    }

    public function test_an_unrelated_employee_cannot_reveal_anything(): void
    {
        $stranger = User::factory()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('hr.employees.reveal', $employee), ['field' => 'drivers_license_number'])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'accessed']);
    }

    public function test_only_the_maskable_fields_can_be_revealed(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr)
            ->postJson(route('hr.employees.reveal', $employee), ['field' => 'basic_salary'])
            ->assertUnprocessable();
    }

    public function test_the_mask_hides_most_of_a_short_value(): void
    {
        $this->assertSame('••••••5678', Employee::mask('34-1234567-8'));
        $this->assertSame('••••••34', Employee::mask('1234'));
        $this->assertNull(Employee::mask(null));
        $this->assertNull(Employee::mask('  '));
    }

    // --- Audit trail of reads ---------------------------------------------

    public function test_opening_somebody_elses_record_is_logged(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr)->get(route('hr.employees.show', $employee))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'accessed',
            'user_id' => $hr->id,
            'auditable_type' => Employee::class,
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_opening_your_own_record_is_not_logged(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get(route('hr.my-profile'))->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'accessed']);
    }

    public function test_opening_the_edit_form_is_logged(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr)->get(route('hr.employees.edit', $employee))->assertOk();

        $entry = AuditLog::where('event', 'accessed')->latest('id')->first();
        $this->assertSame('edit_form', $entry->new_values['how']);
    }

    public function test_reading_a_record_over_the_api_is_logged(): void
    {
        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr, 'sanctum')->getJson("/api/v1/employees/{$employee->id}")->assertOk();

        $entry = AuditLog::where('event', 'accessed')->latest('id')->first();
        $this->assertSame('api_view', $entry->new_values['how']);
    }

    public function test_a_supervisor_still_cannot_edit_a_reports_record(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);

        $this->actingAs($supervisorUser)->get(route('hr.employees.show', $report))->assertOk();
        $this->actingAs($supervisorUser)->get(route('hr.employees.edit', $report))->assertForbidden();
    }

    // --- Privacy notice (RA 10173) ----------------------------------------

    public function test_someone_who_has_not_read_the_notice_is_sent_to_it(): void
    {
        $user = User::factory()->withoutPrivacyAcknowledgement()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('privacy.notice'));

        $this->actingAs($user)->get(route('privacy.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/PrivacyNotice')
                ->where('acknowledgedAt', null)
                ->where('version', config('privacy.notice_version')));
    }

    public function test_acknowledging_needs_the_box_ticked(): void
    {
        $user = User::factory()->withoutPrivacyAcknowledgement()->create();

        $this->actingAs($user)->post(route('privacy.acknowledge'), [])
            ->assertSessionHasErrors('understood');

        $this->assertNull($user->fresh()->privacy_acknowledged_at);
    }

    public function test_acknowledging_records_the_version_date_and_an_audit_row_then_continues(): void
    {
        $user = User::factory()->withoutPrivacyAcknowledgement()->create();

        $this->actingAs($user)->get('/hr/leave');

        $this->actingAs($user)
            ->post(route('privacy.acknowledge'), ['understood' => '1'])
            ->assertRedirect('/hr/leave');

        $fresh = $user->fresh();
        $this->assertSame(config('privacy.notice_version'), $fresh->privacy_notice_version);
        $this->assertNotNull($fresh->privacy_acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'privacy_acknowledged', 'user_id' => $user->id]);

        $this->actingAs($fresh)->get('/dashboard')->assertOk();
    }

    public function test_a_changed_notice_has_to_be_read_again(): void
    {
        $user = User::factory()->create();

        config(['privacy.notice_version' => '2099-01-01']);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('privacy.notice'));
    }

    /** The two holds must not bounce somebody between each other. */
    public function test_a_pending_password_change_comes_before_the_notice(): void
    {
        $user = User::factory()->withoutPrivacyAcknowledgement()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('settings.security'));
        $this->actingAs($user)->get(route('settings.security'))->assertOk();
    }

    public function test_the_notice_can_be_reread_after_acknowledging(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('privacy.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->whereNot('acknowledgedAt', null));
    }

    public function test_the_notice_names_the_scanner_processor_only_when_scanning_is_on(): void
    {
        $user = User::factory()->create();

        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => null]);
        $this->actingAs($user)->get(route('privacy.notice'))
            ->assertInertia(fn (Assert $page) => $page->where('scannerProcessor', null));
    }

    public function test_the_api_is_not_held_by_the_notice(): void
    {
        $user = User::factory()->hrStaff()->withoutPrivacyAcknowledgement()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertOk();
    }
}
