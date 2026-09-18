<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in as somebody else, and the four things that hold it in place.
 *
 * Impersonation is an account takeover with a button if nothing constrains
 * it, so these are not coverage for coverage's sake: each test is one of the
 * constraints the feature is only defensible because of.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_administrator_can_sign_in_as_an_employee(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)
            ->post("/settings/users/{$employee->id}/impersonate")
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($employee);
        $this->assertSame($admin->id, session(ImpersonationService::SESSION_KEY));
    }

    /**
     * The row is written while the administrator is still themselves.
     *
     * Written after the switch, the row recording the *start* of an
     * impersonation would itself be attributed to the person being
     * impersonated — the trail saying the employee began impersonating
     * themselves.
     */
    public function test_starting_one_is_recorded_against_the_administrator(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");

        $row = AuditLog::where('event', ImpersonationService::EVENT_STARTED)->sole();

        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame($employee->id, (int) $row->auditable_id);
        $this->assertSame($employee->username, $row->new_values['target_username']);
    }

    /**
     * The whole reason the feature is defensible.
     *
     * Once impersonating, `Auth::id()` is the employee, so a record edited by
     * the administrator would otherwise be filed under the employee with
     * nothing on the row to say otherwise.
     */
    public function test_every_row_written_while_impersonating_names_the_administrator(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $record = Employee::factory()->create();

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");

        // Any audited write at all, done by the impersonated session.
        $record->update(['first_name' => 'Renamed']);

        $row = AuditLog::where('auditable_type', Employee::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertSame(
            $employee->id,
            $row->user_id,
            'user_id keeps meaning "the account this ran as", which every existing screen reads it as.',
        );
        $this->assertSame(
            $admin->id,
            $row->impersonated_by,
            'The administrator behind the keyboard has to be on the row.',
        );
    }

    /** An ordinary session leaves the column null rather than guessing. */
    public function test_an_ordinary_session_records_no_impersonator(): void
    {
        $admin = $this->superAdmin();
        $record = Employee::factory()->create();

        $this->actingAs($admin);
        $record->update(['first_name' => 'Renamed']);

        $row = AuditLog::where('auditable_type', Employee::class)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNull($row->impersonated_by);
    }

    /**
     * A peer is the one target with no support value and real cover for
     * misuse: they can already see everything you can, and an action taken as
     * them is an action the trail attributes to another administrator.
     */
    public function test_a_super_administrator_cannot_be_impersonated(): void
    {
        $admin = $this->superAdmin();
        $peer = $this->superAdmin();

        $this->actingAs($admin)
            ->post("/settings/users/{$peer->id}/impersonate")
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationService::SESSION_KEY));
    }

    /**
     * `EnsureAccountIsActive` would sign the session straight back out on the
     * next request, so this would look broken rather than refused.
     */
    public function test_a_deactivated_account_cannot_be_impersonated(): void
    {
        $admin = $this->superAdmin();
        $inactive = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => false]);

        $this->actingAs($admin)
            ->post("/settings/users/{$inactive->id}/impersonate")
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($admin);
    }

    /** Everybody below super administrator is refused outright. */
    public function test_an_administrator_may_not_impersonate(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)
            ->post("/settings/users/{$employee->id}/impersonate")
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_stopping_hands_the_session_back_and_is_recorded(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");
        $this->assertAuthenticatedAs($employee);

        $this->post('/settings/impersonate/stop')->assertRedirect('/settings/users');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationService::SESSION_KEY));

        $row = AuditLog::where('event', ImpersonationService::EVENT_STOPPED)->sole();
        $this->assertSame($admin->id, $row->user_id);
    }

    /**
     * Reading somebody's screen is support; changing what they sign in with is
     * takeover — and the employee's password simply ceasing to work, with a
     * trail saying they changed it themselves, is the quiet version of it.
     */
    public function test_the_credential_routes_are_closed_while_impersonating(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");

        $this->put('/settings/security/password', [
            'current_password' => 'password',
            'password' => 'Str0ng-New-Password!',
            'password_confirmation' => 'Str0ng-New-Password!',
        ])->assertForbidden();
    }

    /** Nesting one impersonation inside another would lose the administrator. */
    public function test_a_second_impersonation_cannot_be_started_from_inside_one(): void
    {
        $admin = $this->superAdmin();
        $first = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $second = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$first->id}/impersonate");

        $this->post("/settings/users/{$second->id}/impersonate")->assertForbidden();

        $this->assertAuthenticatedAs($first);
        $this->assertSame($admin->id, session(ImpersonationService::SESSION_KEY));
    }

    /**
     * An employee who has never accepted the privacy notice can still be
     * impersonated **and** left again.
     *
     * Found by driving the real route against a live employee rather than a
     * factory: `RequirePrivacyAcknowledgement` held the impersonated session,
     * and because it held the *stop* request too the administrator could
     * neither go forward nor go back. The suite missed it because
     * `UserFactory` defaults to acknowledged — so this is the one test in the
     * file that has to ask for the opposite.
     */
    public function test_an_unacknowledged_employee_can_be_impersonated_and_left(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()
            ->withoutPrivacyAcknowledgement()
            ->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");
        $this->assertAuthenticatedAs($employee);

        // Not held on the notice.
        $this->get('/dashboard')->assertOk();

        // And the way out still works.
        $this->post('/settings/impersonate/stop')->assertRedirect('/settings/users');
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * An employee flagged with must_change_password (e.g. a newly provisioned
     * account) can be impersonated and left without being trapped on the
     * security password-change screen.
     */
    public function test_an_employee_requiring_password_change_can_be_impersonated_and_left(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'must_change_password' => true,
        ]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");
        $this->assertAuthenticatedAs($employee);

        // Not held on the password change screen.
        $this->get('/dashboard')->assertOk();

        // And the way out works.
        $this->post('/settings/impersonate/stop')->assertRedirect('/settings/users');
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * The notice is a person saying they have read what is collected about
     * them, and accepting it writes their name and the date onto the account.
     * An administrator wearing their session must not sign that for them.
     */
    public function test_the_privacy_notice_cannot_be_accepted_while_impersonating(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()
            ->withoutPrivacyAcknowledgement()
            ->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");

        $this->post('/privacy-notice')->assertForbidden();

        $this->assertFalse($employee->fresh()->hasAcknowledgedPrivacyNotice());
    }

    /** Ordinary screens still work — an impersonation that could only read would not reproduce a bug report. */
    public function test_an_impersonated_session_can_still_open_its_own_screens(): void
    {
        $admin = $this->superAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($admin)->post("/settings/users/{$employee->id}/impersonate");

        $this->get('/dashboard')->assertOk();
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }
}
