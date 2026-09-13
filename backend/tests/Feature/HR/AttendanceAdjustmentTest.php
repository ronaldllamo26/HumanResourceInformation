<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The exception-handling step: a discrepancy on a DTR becomes a request a
 * supervisor or HR decides on, never an edit the employee makes themselves.
 */
class AttendanceAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_employee_files_a_correction_for_their_own_day(): void
    {
        [$user, $employee] = $this->employeeLogin();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $this->actingAs($user)->post('/hr/timekeeping/adjustments', [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out, left with the 5pm dispatch.',
        ])->assertRedirect();

        $request = AttendanceAdjustment::firstOrFail();

        $this->assertSame(AttendanceAdjustment::STATUS_PENDING, $request->status);
        $this->assertSame($employee->id, $request->employee_id);
        $this->assertSame($user->id, $request->requested_by);

        // Nothing has changed yet, which is the whole point of the queue.
        $this->assertNull(AttendanceLog::firstOrFail()->time_out);
    }

    public function test_approving_applies_the_correction_and_recomputes_the_day(): void
    {
        [$user, $employee] = $this->employeeLogin();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
            'hours_worked' => 0,
        ]);

        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out, left with the 5pm dispatch.',
        ]);

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
                'status' => 'approved',
                'remarks' => 'Confirmed with dispatch.',
            ])
            ->assertRedirect();

        $log = AttendanceLog::firstOrFail();

        $this->assertSame('17:00', $log->time_out->format('H:i'));
        // Written through TimekeepingService::record(), so the figures are
        // recomputed by the same calculator payroll depends on rather than
        // patched in place.
        $this->assertEqualsWithDelta(9.0, (float) $log->hours_worked, 0.01);
        $this->assertStringContainsString('Adjustment #', $log->remarks);

        $request->refresh();
        $this->assertSame(AttendanceAdjustment::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->decided_at);
    }

    public function test_a_blank_punch_leaves_the_existing_one_alone(): void
    {
        [$user, $employee] = $this->employeeLogin();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        // Only a time-out is asked for. Reading the blank time-in as "erase
        // this" would destroy the half of the day that was already right.
        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out, left with the 5pm dispatch.',
        ]);

        $this->actingAs($this->hr())->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
            'status' => 'approved',
        ]);

        $this->assertSame('08:00', AttendanceLog::firstOrFail()->time_in->format('H:i'));
    }

    public function test_a_day_with_no_record_at_all_can_be_created_by_a_correction(): void
    {
        [$user] = $this->employeeLogin();

        $request = $this->file($user, [
            'log_date' => '2026-08-04',
            'requested_time_in' => '08:00',
            'requested_time_out' => '17:00',
            'reason' => 'Biometric was down; signed the paper log at the gate.',
        ]);

        $this->actingAs($this->hr())->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
            'status' => 'approved',
        ]);

        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_rejecting_leaves_the_record_untouched(): void
    {
        [$user, $employee] = $this->employeeLogin();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '23:00',
            'reason' => 'Claiming a late finish that dispatch cannot confirm.',
        ]);

        $this->actingAs($this->hr())->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
            'status' => 'rejected',
            'remarks' => 'Dispatch log shows the vehicle back at 17:10.',
        ]);

        $this->assertNull(AttendanceLog::firstOrFail()->time_out);
        $this->assertSame(AttendanceAdjustment::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_nobody_decides_on_their_own_correction(): void
    {
        // Even a supervisor, on their own DTR. The queue exists so that a
        // change to a time record has two people behind it.
        $user = User::factory()->supervisor()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out at the end of the shift.',
        ]);

        $this->actingAs($user)
            ->post("/hr/timekeeping/adjustments/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_a_supervisor_decides_for_a_direct_report(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);

        $reportUser = User::factory()->create();
        Employee::factory()->create([
            'user_id' => $reportUser->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $request = $this->file($reportUser, [
            'log_date' => '2026-08-03',
            'requested_time_in' => '08:00',
            'reason' => 'Tapped in at the wrong terminal that morning.',
        ]);

        $this->actingAs($supervisorUser)
            ->post("/hr/timekeeping/adjustments/{$request->id}/decide", ['status' => 'approved'])
            ->assertRedirect();
    }

    public function test_an_unrelated_supervisor_cannot_decide(): void
    {
        $stranger = User::factory()->supervisor()->create();
        Employee::factory()->create(['user_id' => $stranger->id]);

        [$user] = $this->employeeLogin();
        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_in' => '08:00',
            'reason' => 'Tapped in at the wrong terminal that morning.',
        ]);

        $this->actingAs($stranger)
            ->post("/hr/timekeeping/adjustments/{$request->id}/decide", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        [$user] = $this->employeeLogin();
        $request = $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_in' => '08:00',
            'reason' => 'Tapped in at the wrong terminal that morning.',
        ]);

        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
            'status' => 'approved',
        ])->assertRedirect();

        // Re-deciding would apply the same correction twice, against a record
        // that has already moved.
        $this->actingAs($hr)->post("/hr/timekeeping/adjustments/{$request->id}/decide", [
            'status' => 'rejected',
        ])->assertForbidden();
    }

    public function test_a_request_that_asks_for_nothing_is_refused(): void
    {
        [$user] = $this->employeeLogin();

        $this->actingAs($user)
            ->post('/hr/timekeeping/adjustments', [
                'log_date' => '2026-08-03',
                'reason' => 'Something was wrong with this day.',
            ])
            ->assertSessionHasErrors('requested_time_in');
    }

    public function test_two_open_requests_for_one_day_are_refused(): void
    {
        [$user] = $this->employeeLogin();

        $payload = [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out at the end of the shift.',
        ];

        $this->actingAs($user)->post('/hr/timekeeping/adjustments', $payload)->assertRedirect();

        // Two pending corrections for one Tuesday are two approvals against
        // one row, and the second silently overwrites the first.
        $this->actingAs($user)
            ->post('/hr/timekeeping/adjustments', $payload)
            ->assertSessionHasErrors('log_date');

        $this->assertDatabaseCount('attendance_adjustments', 1);
    }

    public function test_an_account_with_no_employee_record_cannot_file(): void
    {
        // HR can already write a time record directly, so a request queue
        // exists for the people who cannot — and a pure system account has no
        // DTR to correct.
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/adjustments', [
                'log_date' => '2026-08-03',
                'requested_time_out' => '17:00',
                'reason' => 'Forgot to tap out at the end of the shift.',
            ])
            ->assertForbidden();
    }

    public function test_the_queue_shows_what_the_day_currently_says(): void
    {
        [$user, $employee] = $this->employeeLogin();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => null,
        ]);

        $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out, left with the 5pm dispatch.',
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/adjustments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Adjustments')
                ->has('requests.data', 1)
                // Read live, so an approver decides against the record as it
                // stands rather than against a snapshot.
                ->where('requests.data.0.current.time_in', '08:00')
                ->where('requests.data.0.current.time_out', null)
                ->where('requests.data.0.requested.time_out', '17:00')
                ->where('requests.data.0.can.decide', true),
            );
    }

    public function test_employees_only_see_their_own_requests(): void
    {
        [$user] = $this->employeeLogin();
        $this->file($user, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out at the end of the shift.',
        ]);

        [$other] = $this->employeeLogin();
        $this->file($other, [
            'log_date' => '2026-08-03',
            'requested_time_out' => '17:00',
            'reason' => 'Forgot to tap out at the end of the shift.',
        ]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/adjustments')
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/adjustments')
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 2));
    }

    /** @return array{0: User, 1: Employee} */
    private function employeeLogin(): array
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        return [$user, $employee];
    }

    private function file(User $user, array $payload): AttendanceAdjustment
    {
        $this->actingAs($user)
            ->post('/hr/timekeeping/adjustments', $payload)
            ->assertRedirect();

        return AttendanceAdjustment::where('employee_id', $user->employee->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
