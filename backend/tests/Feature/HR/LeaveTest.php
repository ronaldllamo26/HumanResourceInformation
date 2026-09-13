<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_leave_screen_lists_requests(): void
    {
        LeaveRequest::factory()->count(3)->create();

        $this->actingAs($this->hr())
            ->get('/hr/leave')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Leave/Index')
                ->has('requests.data', 3)
                ->where('summary.total', 3),
            );
    }

    public function test_filing_computes_working_days_and_skips_holidays(): void
    {
        [$employee, $type] = $this->employeeWithCredits(15);

        // 2026-04-06 to 2026-04-10 is Mon–Fri; the 9th is Araw ng Kagitingan.
        Holiday::create(['name' => 'Araw ng Kagitingan', 'date' => '2026-04-09', 'type' => 'regular']);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-10',
            'reason' => 'Family obligations out of town.',
        ])->assertRedirect();

        // Five calendar days less the holiday.
        $this->assertEquals(4.0, (float) LeaveRequest::firstOrFail()->days_requested);
    }

    public function test_rest_days_do_not_consume_credits(): void
    {
        [$employee, $type] = $this->employeeWithCredits(15);
        $shift = Shift::factory()->create();

        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5], // weekdays only
        ]);

        // 2026-04-10 is a Friday; the 11th and 12th are the weekend.
        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-13',
            'reason' => 'Long weekend for a family event.',
        ]);

        $this->assertEquals(2.0, (float) LeaveRequest::firstOrFail()->days_requested);
    }

    public function test_a_half_day_counts_as_half(): void
    {
        [$employee, $type] = $this->employeeWithCredits(15);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-06',
            'is_half_day' => true,
            'half_day_period' => 'afternoon',
            'reason' => 'Medical appointment in the afternoon.',
        ]);

        $this->assertEquals(0.5, (float) LeaveRequest::firstOrFail()->days_requested);
    }

    public function test_filing_beyond_the_available_balance_is_rejected(): void
    {
        [$employee, $type] = $this->employeeWithCredits(2);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-10',
            'reason' => 'Extended trip out of the country.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_open_requests_hold_credits_so_they_cannot_be_filed_twice(): void
    {
        [$employee, $type] = $this->employeeWithCredits(3);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-08',
            'reason' => 'First trip already planned.',
        ])->assertRedirect();

        // Three credits are now spoken for even though none are spent yet.
        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-20',
            'end_date' => '2026-04-22',
            'reason' => 'Second trip in the same month.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_overlapping_dates_are_rejected(): void
    {
        [$employee, $type] = $this->employeeWithCredits(20);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-08',
            'reason' => 'Planned family leave.',
        ])->assertRedirect();

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-07',
            'end_date' => '2026-04-09',
            'reason' => 'Overlapping second request.',
        ])->assertSessionHasErrors('start_date');
    }

    public function test_unpaid_leave_ignores_the_credit_ledger(): void
    {
        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);
        $type = LeaveType::factory()->unpaid()->create();

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-10',
            'reason' => 'Unpaid personal time.',
        ])->assertRedirect();

        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_a_type_requiring_an_attachment_refuses_a_request_without_one(): void
    {
        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);
        $type = LeaveType::factory()->requiringAttachment()->create();
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'credits_earned' => 15,
        ]);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-06',
            'reason' => 'Sick and needing rest.',
        ])->assertSessionHasErrors('attachment');
    }

    public function test_an_employee_can_only_file_for_themselves(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);
        [$other, $type] = $this->employeeWithCredits(15);

        $this->actingAs($user)->post('/hr/leave', [
            'employee_id' => $other->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-06',
            'reason' => 'Filing on behalf of a colleague.',
        ])->assertSessionHasErrors('employee_id');
    }

    // --- Approval workflow ------------------------------------------------

    public function test_hr_approves_in_one_step_and_the_credits_move(): void
    {
        [$employee, $type, $request] = $this->pendingRequest();

        // One signature, not two. The supervisor endorsement that used to
        // come first never decided anything on its own — only HR's step ever
        // moved credits — so it bought a delay rather than a decision.
        $this->actingAs($this->hr())
            ->post("/hr/leave/{$request->id}/approve", ['remarks' => 'Coverage arranged.'])
            ->assertRedirect();

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertEquals(2.0, (float) $this->balance($employee, $type)->credits_used);
    }

    public function test_a_supervisor_can_no_longer_approve_their_own_report(): void
    {
        [$employee, , $request] = $this->pendingRequest();

        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $employee->update(['supervisor_id' => $supervisor->id]);

        // Deciding is HR's alone. A supervisor still *sees* their reports'
        // leave — that is `view`, and it is untouched.
        $this->actingAs($supervisorUser)
            ->post("/hr/leave/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
    }

    /**
     * Rows left in `supervisor_approved` when the rule changed are real
     * requests somebody is still waiting on, which is why the status is not
     * gone from the model.
     */
    public function test_a_request_left_mid_workflow_can_still_be_approved(): void
    {
        [$employee, $type, $request] = $this->pendingRequest();
        $request->update(['status' => LeaveRequest::STATUS_SUPERVISOR_APPROVED]);

        $this->actingAs($this->hr())
            ->post("/hr/leave/{$request->id}/approve")
            ->assertRedirect();

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertEquals(2.0, (float) $this->balance($employee, $type)->credits_used);
    }

    public function test_nobody_can_approve_their_own_leave(): void
    {
        $user = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $type = LeaveType::factory()->create();
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => now()->year,
            'credits_earned' => 15,
        ]);

        $request = LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
        ]);

        $this->actingAs($user)->post("/hr/leave/{$request->id}/approve")->assertForbidden();
    }

    public function test_an_unrelated_supervisor_cannot_approve_either(): void
    {
        [, , $request] = $this->pendingRequest();

        $user = User::factory()->supervisor()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post("/hr/leave/{$request->id}/approve")->assertForbidden();
    }

    public function test_rejection_requires_a_reason(): void
    {
        [, , $request] = $this->pendingRequest();

        $this->actingAs($this->hr())
            ->post("/hr/leave/{$request->id}/reject", [])
            ->assertSessionHasErrors('remarks');

        $this->assertSame(LeaveRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_cancelling_an_approved_request_returns_the_credits(): void
    {
        [$employee, $type, $request] = $this->pendingRequest();
        $hr = $this->hr();

        // One approval, not two: the supervisor step is gone.
        $this->actingAs($hr)->post("/hr/leave/{$request->id}/approve");

        $this->assertEquals(2.0, (float) $this->balance($employee, $type)->credits_used);

        $this->actingAs($hr)->post("/hr/leave/{$request->id}/cancel")->assertRedirect();

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertEquals(0.0, (float) $this->balance($employee, $type)->credits_used);
    }

    public function test_a_decided_request_cannot_be_cancelled(): void
    {
        [, , $request] = $this->pendingRequest();
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/leave/{$request->id}/reject", ['remarks' => 'Short staffed.']);

        $this->actingAs($hr)->post("/hr/leave/{$request->id}/cancel")->assertForbidden();
    }

    // --- Scoping and notifications ---------------------------------------

    public function test_employees_only_see_their_own_requests(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        LeaveRequest::factory()->create(['employee_id' => $own->id]);
        LeaveRequest::factory()->count(3)->create();

        $this->actingAs($user)
            ->get('/hr/leave')
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));
    }

    public function test_the_pending_badge_counts_only_what_this_user_must_act_on(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);

        LeaveRequest::factory()->count(2)->create(['employee_id' => $report->id]);
        LeaveRequest::factory()->create();                    // somebody else's report
        LeaveRequest::factory()->endorsed()->create();        // left mid-workflow

        /*
         * Nothing for a supervisor any more. They were counted here while
         * endorsing was a step they took; with the decision HR's alone, a
         * badge they cannot act on only teaches them to ignore the bell.
         */
        $this->actingAs($supervisorUser)
            ->get('/hr/leave')
            ->assertInertia(fn (Assert $page) => $page->where('pendingApprovals', 0));

        // HR sees every request awaiting a decision — including the one left
        // in `supervisor_approved` before the rule changed.
        $this->actingAs($this->hr())
            ->get('/hr/leave')
            ->assertInertia(fn (Assert $page) => $page->where('pendingApprovals', 4));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/leave')->assertRedirect('/login');
    }

    // --- Attachments ------------------------------------------------------

    public function test_attachments_are_private_and_downloaded_through_an_authorized_route(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $employee = Employee::factory()->create(['user_id' => User::factory()->create()->id]);
        $type = LeaveType::factory()->requiringAttachment()->create();
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'credits_earned' => 15,
        ]);

        $this->actingAs($employee->user)->post('/hr/leave', [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-06',
            'reason' => 'Sick with a medical certificate.',
            'attachment' => UploadedFile::fake()->create('medcert.pdf', 60, 'application/pdf'),
        ])->assertRedirect();

        $request = LeaveRequest::firstOrFail();

        Storage::disk('local')->assertExists($request->attachment_path);
        Storage::disk('public')->assertMissing($request->attachment_path);

        $this->post('/logout');
        $this->get("/hr/leave/{$request->id}/attachment")->assertRedirect('/login');

        $outsider = User::factory()->create();
        Employee::factory()->create(['user_id' => $outsider->id]);

        $this->actingAs($outsider)
            ->get("/hr/leave/{$request->id}/attachment")
            ->assertForbidden();
    }

    // --- Helpers ----------------------------------------------------------

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function balance(Employee $employee, LeaveType $type): LeaveBalance
    {
        return app(LeaveService::class)->balanceFor($employee, $type, 2026);
    }

    /**
     * An employee with credits, a login, and nobody else's leave to file.
     *
     * The login is the point: everybody files their own leave now, HR
     * included, so a test that files has to act as the person the leave is
     * for. It used to act as HR and name any employee, which is exactly the
     * door that was closed.
     *
     * @return array{0: Employee, 1: LeaveType}
     */
    private function employeeWithCredits(float $credits): array
    {
        $employee = Employee::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
        $type = LeaveType::factory()->create(['default_credits' => $credits]);

        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => 2026,
            'credits_earned' => $credits,
        ]);

        return [$employee, $type];
    }

    /** @return array{0: Employee, 1: LeaveType, 2: LeaveRequest} */
    private function pendingRequest(): array
    {
        [$employee, $type] = $this->employeeWithCredits(15);

        $request = LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-07',
            'days_requested' => 2,
        ]);

        return [$employee, $type, $request];
    }
}
