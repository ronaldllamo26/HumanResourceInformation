<?php

namespace Tests\Feature\Settings;

use App\Models\AccountChangeRequest;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_security_page_with_change_requests(): void
    {
        $user = User::factory()->create();
        AccountChangeRequest::create([
            'user_id' => $user->id,
            'current_username' => $user->username,
            'requested_username' => 'new.name',
            'current_email' => 'old@gmail.com',
            'requested_email' => 'new@gmail.com',
            'staff_notes' => 'I updated my personal email and name.',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get('/settings/security')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Security')
                ->has('changeRequests', 1)
                ->where('changeRequests.0.requested_username', 'new.name')
                ->where('changeRequests.0.status', 'pending'));
    }

    public function test_staff_can_submit_account_change_request(): void
    {
        $user = User::factory()->create([
            'username' => 'john.doe@primepower.com',
            'otp_email' => 'john.old@gmail.com',
        ]);

        $response = $this->actingAs($user)
            ->withSession([OtpService::SESSION_KEY => now()->timestamp])
            ->post('/settings/security/change-request', [
                'requested_username' => 'johnny',
                'requested_email' => 'john.new@gmail.com',
                'staff_notes' => 'Pinalitan ko po ang aking personal email dahil hindi ko na ma-access ang luma kong Gmail.',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('account_change_requests', [
            'user_id' => $user->id,
            'current_username' => 'john.doe@primepower.com',
            'requested_username' => 'johnny',
            'current_email' => 'john.old@gmail.com',
            'requested_email' => 'john.new@gmail.com',
            'staff_notes' => 'Pinalitan ko po ang aking personal email dahil hindi ko na ma-access ang luma kong Gmail.',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);
    }

    public function test_staff_must_provide_notes_when_requesting_changes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/security/change-request', [
                'requested_email' => 'test@gmail.com',
                'staff_notes' => '',
            ])
            ->assertSessionHasErrors('staff_notes');

        $this->assertDatabaseEmpty('account_change_requests');
    }

    public function test_staff_cannot_submit_duplicate_pending_request(): void
    {
        $user = User::factory()->create();
        AccountChangeRequest::create([
            'user_id' => $user->id,
            'current_username' => $user->username,
            'requested_email' => 'pending@gmail.com',
            'staff_notes' => 'Initial pending request',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)
            ->post('/settings/security/change-request', [
                'requested_email' => 'another@gmail.com',
                'staff_notes' => 'Second attempt',
            ]);

        $response->assertSessionHas('error');
        $this->assertSame(1, AccountChangeRequest::where('user_id', $user->id)->count());
    }

    public function test_super_admin_receives_change_requests_in_users_view(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->create();

        AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_username' => 'staff.new',
            'requested_email' => 'staff.new@gmail.com',
            'staff_notes' => 'Official name and email update',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($superAdmin)
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users')
                ->where('is_super_admin', true)
                ->has('change_requests', 1)
                ->where('change_requests.0.staff_name', $staff->name));
    }

    public function test_regular_admin_receives_change_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_username' => 'staff.new',
            'staff_notes' => 'Name change',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get('/settings/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users')
                ->where('is_super_admin', false)
                ->where('can_manage_requests', true)
                ->has('change_requests', 1));
    }

    public function test_super_admin_can_approve_account_change_request(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->create([
            'username' => 'old.staff@primepower.com',
            'otp_email' => 'old.staff@gmail.com',
            'otp_email_verified_at' => now(),
        ]);

        $request = AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_username' => 'new.staff',
            'current_email' => $staff->otp_email,
            'requested_email' => 'new.staff@gmail.com',
            'staff_notes' => 'Please update my credentials.',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($superAdmin)
            ->post("/settings/users/requests/{$request->id}/approve", [
                'admin_notes' => 'Approved as requested. Next sign-in requires new email.',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        // Verify request is approved
        $request->refresh();
        $this->assertSame(AccountChangeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($superAdmin->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('Approved as requested. Next sign-in requires new email.', $request->admin_notes);

        // Verify user account updated
        $staff->refresh();
        $this->assertSame('new.staff@primepower.com', $staff->username);
        $this->assertSame('new.staff@gmail.com', $staff->otp_email);
        $this->assertNull($staff->otp_email_verified_at);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'auditable_type' => User::class,
            'auditable_id' => $staff->id,
            'event' => 'account_change_approved',
        ]);
    }

    public function test_super_admin_can_reject_account_change_request_with_notes(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->create([
            'username' => 'current@primepower.com',
            'otp_email' => 'current@gmail.com',
        ]);

        $request = AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_username' => 'bad.username',
            'staff_notes' => 'Please change',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($superAdmin)
            ->post("/settings/users/requests/{$request->id}/reject", [
                'admin_notes' => 'Username format must adhere to company naming convention.',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $request->refresh();
        $this->assertSame(AccountChangeRequest::STATUS_REJECTED, $request->status);
        $this->assertSame($superAdmin->id, $request->decided_by);
        $this->assertSame('Username format must adhere to company naming convention.', $request->admin_notes);

        // Staff credentials remain unchanged
        $staff->refresh();
        $this->assertSame('current@primepower.com', $staff->username);
        $this->assertSame('current@gmail.com', $staff->otp_email);

        // Verify Audit Log
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'auditable_type' => User::class,
            'auditable_id' => $staff->id,
            'event' => 'account_change_rejected',
        ]);
    }

    public function test_non_admin_cannot_approve_or_reject_requests(): void
    {
        $hrStaff = User::factory()->hrStaff()->create();
        $staff = User::factory()->create();

        $request = AccountChangeRequest::create([
            'user_id' => $staff->id,
            'current_username' => $staff->username,
            'requested_email' => 'new@gmail.com',
            'staff_notes' => 'Change my email',
            'status' => AccountChangeRequest::STATUS_PENDING,
        ]);

        // HR staff is forbidden
        $this->actingAs($hrStaff)
            ->post("/settings/users/requests/{$request->id}/approve", [])
            ->assertForbidden();

        $this->actingAs($hrStaff)
            ->post("/settings/users/requests/{$request->id}/reject", ['admin_notes' => 'No'])
            ->assertForbidden();

        // Staff is forbidden
        $this->actingAs($staff)
            ->post("/settings/users/requests/{$request->id}/approve", [])
            ->assertForbidden();

        $this->assertSame(AccountChangeRequest::STATUS_PENDING, $request->refresh()->status);
    }
}
