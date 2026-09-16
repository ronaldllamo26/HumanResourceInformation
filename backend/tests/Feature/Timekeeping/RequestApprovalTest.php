<?php

namespace Tests\Feature\Timekeeping;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\TimeCorrection;
use App\Models\User;
use App\Services\AttendanceCutoffService;
use App\Services\NotificationFeed;

/** Overtime and corrections: file your own, somebody else decides. */
class RequestApprovalTest extends TimekeepingTestCase
{
    public function test_an_employee_files_overtime_for_themselves(): void
    {
        $employee = $this->worker();

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/overtime', ['work_date' => '2026-09-14', 'hours' => 2, 'reason' => 'Late delivery'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('overtime_requests', ['employee_id' => $employee->id, 'status' => 'pending']);
    }

    public function test_nobody_decides_their_own_overtime_not_even_hr(): void
    {
        $hrUser = $this->hr();
        $hrEmployee = $this->worker(['user_id' => $hrUser->id]);
        $request = $this->overtime($hrEmployee);

        $this->actingAs($hrUser)
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_the_supervisor_decides_a_direct_reports_overtime(): void
    {
        $supervisorUser = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $supervisorUser->id]);
        $report = $this->worker(['supervisor_id' => $supervisor->id]);
        $stranger = $this->worker();

        $this->actingAs($supervisorUser)
            ->post("/hr/timekeeping/overtime/{$this->overtime($report)->id}/decide", ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->actingAs($supervisorUser)
            ->post("/hr/timekeeping/overtime/{$this->overtime($stranger)->id}/decide", ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $request = $this->overtime($this->worker());

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/overtime/{$request->id}/decide", ['decision' => 'reject'])
            ->assertSessionHasErrors('remarks');

        $this->assertTrue($request->refresh()->isPending());
    }

    public function test_an_approved_correction_rewrites_the_day_and_keeps_the_punch_it_did_not_mention(): void
    {
        $employee = $this->worker();
        $this->record($employee, '2026-09-14', '08:05', null);

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/corrections', ['work_date' => '2026-09-14', 'time_out' => '17:00', 'reason' => 'Biometric was offline'])
            ->assertSessionHasNoErrors();

        $correction = TimeCorrection::firstOrFail();

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/corrections/{$correction->id}/decide", ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $log = AttendanceLog::firstOrFail();
        $this->assertSame('08:05', $log->time_in->format('H:i'));
        $this->assertSame('17:00', $log->time_out->format('H:i'));
        $this->assertSame(AttendanceLog::STATUS_PRESENT, $log->status);
        $this->assertSame(AttendanceLog::SOURCE_CORRECTION, $log->source);
        $this->assertSame(TimeCorrection::STATUS_APPROVED, $correction->refresh()->status);
    }

    public function test_an_employee_cannot_decide_a_correction(): void
    {
        $employee = $this->worker();
        $correction = TimeCorrection::create([
            'employee_id' => $this->worker()->id, 'work_date' => '2026-09-14',
            'time_out' => '2026-09-14 17:00:00', 'reason' => 'x', 'status' => 'pending',
        ]);

        $this->actingAs($employee->user)
            ->post("/hr/timekeeping/corrections/{$correction->id}/decide", ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_nothing_in_a_closed_cutoff_can_be_filed(): void
    {
        $employee = $this->worker();
        app(AttendanceCutoffService::class)->close($this->period(), $this->hr());

        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/overtime', ['work_date' => '2026-08-04', 'hours' => 2, 'reason' => 'x'])
            ->assertSessionHasErrors('work_date');
    }

    public function test_the_bell_counts_requests_to_decide_but_never_your_own(): void
    {
        $hrUser = $this->hr();
        $this->overtime($this->worker());
        $this->overtime($this->worker(['user_id' => $hrUser->id]));

        $feed = app(NotificationFeed::class)->for($hrUser);

        $this->assertSame(1, $feed['count']);
        $this->assertNotNull(collect($feed['items'])->firstWhere('key', 'overtime'));
    }

    private function overtime(Employee $employee): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_date' => '2026-09-14',
            'hours' => 2,
            'reason' => 'Late delivery',
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);
    }
}
