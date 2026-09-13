<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overtime_screen_lists_requests(): void
    {
        $this->file(Employee::factory()->create());

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/overtime')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Overtime')
                ->has('requests.data', 1)
                ->where('summary.pending', 1),
            );
    }

    public function test_hours_are_computed_from_the_filed_window(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->loginFor($employee))->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '20:30',
            'reason' => 'Month-end dispatch backlog.',
        ])->assertRedirect();

        $this->assertEquals(3.5, (float) OvertimeRequest::firstOrFail()->hours);
    }

    public function test_overtime_running_past_midnight_rolls_to_the_next_day(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->loginFor($employee))->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '22:00',
            'end_time' => '02:00',
            'reason' => 'Overnight delivery run.',
        ]);

        $request = OvertimeRequest::firstOrFail();

        $this->assertEquals(4.0, (float) $request->hours);
        $this->assertSame('2026-03-11 02:00', $request->end_time->format('Y-m-d H:i'));
    }

    public function test_an_employee_can_only_file_for_themselves(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);
        $other = Employee::factory()->create();

        $this->actingAs($user)->post('/hr/timekeeping/overtime', [
            'employee_id' => $other->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Filing for a colleague.',
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('overtime_requests', 0);
    }

    public function test_a_duplicate_open_request_for_the_same_day_is_rejected(): void
    {
        $employee = Employee::factory()->create();
        $filer = $this->loginFor($employee);

        $payload = [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Backlog clearing work.',
        ];

        $this->actingAs($filer)->post('/hr/timekeeping/overtime', $payload)->assertRedirect();
        $this->actingAs($filer)->post('/hr/timekeeping/overtime', $payload)
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('overtime_requests', 1);
    }

    public function test_a_supervisor_can_approve_a_direct_report(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);

        $request = $this->file($report);

        $this->actingAs($supervisorUser)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", [
                'status' => 'approved',
                'remarks' => 'Confirmed with dispatch.',
            ])
            ->assertRedirect();

        $request->refresh();

        $this->assertSame(OvertimeRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($supervisorUser->id, $request->approved_by);
        $this->assertNotNull($request->acted_at);
    }

    public function test_nobody_can_approve_their_own_overtime(): void
    {
        $user = User::factory()->supervisor()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $request = $this->file($employee);

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame(OvertimeRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_an_unrelated_supervisor_cannot_approve(): void
    {
        $user = User::factory()->supervisor()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $request = $this->file(Employee::factory()->create());

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->file(Employee::factory()->create());
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$request->id}/decide", [
            'status' => 'approved',
        ])->assertRedirect();

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$request->id}/decide", [
            'status' => 'rejected',
        ])->assertForbidden();

        $this->assertSame(OvertimeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_the_requester_can_cancel_while_pending(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $request = $this->file($employee);

        $this->actingAs($user)
            ->post("/hr/timekeeping/overtime/{$request->id}/cancel")
            ->assertRedirect();

        $this->assertSame(OvertimeRequest::STATUS_CANCELLED, $request->fresh()->status);
    }

    public function test_only_approved_hours_are_summarised(): void
    {
        $hr = $this->hr();
        $approved = $this->file(Employee::factory()->create());
        $this->file(Employee::factory()->create());

        $this->actingAs($hr)->post("/hr/timekeeping/overtime/{$approved->id}/decide", [
            'status' => 'approved',
        ]);

        $this->actingAs($hr)
            ->get('/hr/timekeeping/overtime')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.approved', 1)
                ->where('summary.pending', 1)
                // JSON has one number type, so a whole 2.0 arrives as 2.
                ->where('summary.approved_hours', 2),
            );
    }

    public function test_a_reason_is_required(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->loginFor($employee))->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'ot',
        ])->assertSessionHasErrors('reason');
    }

    public function test_hr_cannot_file_overtime_for_somebody_else(): void
    {
        $employee = Employee::factory()->create();
        $hr = $this->hr();
        Employee::factory()->create(['user_id' => $hr->id]);

        // HR decides on these, so HR filing one would make the same person
        // the claimant and an approver of the claim.
        $this->actingAs($hr)->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => '2026-03-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Filing on behalf of a driver.',
        ])->assertSessionHasErrors('employee_id');

        $this->assertDatabaseCount('overtime_requests', 0);
    }

    public function test_an_account_with_no_employee_record_cannot_file_at_all(): void
    {
        // A pure system account has no 201 file, so it has no overtime to
        // claim — the form is not offered and the endpoint refuses it.
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/overtime', [
                'employee_id' => Employee::factory()->create()->id,
                'date' => '2026-03-10',
                'start_time' => '17:00',
                'end_time' => '19:00',
                'reason' => 'Extra dispatch coverage required.',
            ])
            ->assertForbidden();
    }

    public function test_hr_cannot_edit_somebody_elses_request(): void
    {
        $request = $this->file(Employee::factory()->create());

        $this->actingAs($this->hr())
            ->put("/hr/timekeeping/overtime/{$request->id}", [
                'employee_id' => $request->employee_id,
                'date' => '2026-03-11',
                'start_time' => '17:00',
                'end_time' => '18:00',
                'reason' => 'Trimmed by HR without asking.',
            ])
            ->assertForbidden();
    }

    public function test_hr_still_sees_and_decides_on_everybody_s_requests(): void
    {
        $this->file(Employee::factory()->create());
        $request = $this->file(Employee::factory()->create());

        $hr = $this->hr();

        $this->actingAs($hr)
            ->get('/hr/timekeeping/overtime')
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 2));

        $this->actingAs($hr)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(OvertimeRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    /**
     * The login an employee files with, provisioned if the record has none —
     * which is what HR does from the employee form. Overtime is now filed by
     * the person claiming it and by nobody else, so every test that needs a
     * request on the table needs one of these.
     */
    private function loginFor(Employee $employee): User
    {
        if ($employee->user_id !== null) {
            return $employee->user;
        }

        $user = User::factory()->create();
        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    private function file(Employee $employee): OvertimeRequest
    {
        $this->actingAs($this->loginFor($employee))->post('/hr/timekeeping/overtime', [
            'employee_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'start_time' => '17:00',
            'end_time' => '19:00',
            'reason' => 'Extra dispatch coverage required.',
        ])->assertRedirect();

        return OvertimeRequest::where('employee_id', $employee->id)->latest('id')->firstOrFail();
    }
}
