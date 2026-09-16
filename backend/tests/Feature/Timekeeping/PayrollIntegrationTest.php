<?php

namespace Tests\Feature\Timekeeping;

use App\Models\DisciplinaryAction;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Services\PayrollReadinessChecker;
use App\Services\PayrollService;

/** Module 2 → Module 4: what payroll takes from the time records. */
class PayrollIntegrationTest extends TimekeepingTestCase
{
    public function test_payroll_reads_lateness_undertime_absences_and_night_differential(): void
    {
        $employee = $this->worker();
        $period = $this->period();

        $this->record($employee, '2026-08-03', '08:30', '17:00'); // 30 late
        $this->record($employee, '2026-08-04', '08:00', '16:00'); // 60 undertime
        $this->record($employee, '2026-08-05', null, null);       // absent
        $this->record($employee, '2026-08-06', '08:00', '23:00'); // 60 minutes night differential

        $inputs = app(PayrollService::class)->gatherInputs($period, $employee);

        $this->assertSame(3.0, $inputs['days_worked']);
        $this->assertSame(30, $inputs['late_minutes']);
        $this->assertSame(60, $inputs['undertime_minutes']);
        $this->assertSame(1.0, $inputs['absent_days']);
        $this->assertSame(1.0, $inputs['night_diff_hours']);
    }

    public function test_only_approved_overtime_is_paid(): void
    {
        $employee = $this->worker();
        $period = $this->period();

        foreach ([['2026-08-03', 'approved', 2], ['2026-08-04', 'pending', 3], ['2026-08-05', 'rejected', 4]] as [$date, $status, $hours]) {
            OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'hours' => $hours, 'reason' => 'x', 'status' => $status]);
        }

        $this->assertSame(2.0, app(PayrollService::class)->gatherInputs($period, $employee)['overtime_hours']);
    }

    public function test_an_absence_covered_by_approved_leave_is_not_deducted_as_an_absence(): void
    {
        $employee = $this->worker();
        $period = $this->period();
        $this->record($employee, '2026-08-05', null, null);

        LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create(['is_paid' => true])->id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
            'days_requested' => 1,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);

        $this->assertSame(0.0, app(PayrollService::class)->gatherInputs($period, $employee)['absent_days']);
    }

    public function test_working_a_regular_holiday_pays_the_premium(): void
    {
        $employee = $this->worker();
        $period = $this->period('2026-06-01', '2026-06-15');
        Holiday::create(['date' => '2026-06-12', 'name' => 'Independence Day', 'type' => Holiday::TYPE_REGULAR]);

        $this->record($employee, '2026-06-12', '08:00', '17:00'); // 8 hours worked

        $hourly = 26100 * 12 / 261 / 8; // 150.00

        // +100% on top of the day the salary already pays.
        $this->assertEqualsWithDelta(8 * $hourly, app(PayrollService::class)->gatherInputs($period, $employee)['holiday_pay'], 0.01);
    }

    public function test_readiness_flags_attendance_that_would_pay_wrong(): void
    {
        $employee = $this->worker();
        $period = $this->period();
        $this->record($employee, '2026-08-03', '08:00', null);
        OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => '2026-08-04', 'hours' => 2, 'reason' => 'x', 'status' => 'pending']);
        $this->worker(); // nobody recorded a day for this one

        $checks = collect(app(PayrollReadinessChecker::class)->check($period)['checks'])->keyBy('key');

        $this->assertSame(PayrollReadinessChecker::SEVERITY_BLOCKER, $checks['incomplete_punches']['severity']);
        $this->assertSame(PayrollReadinessChecker::SEVERITY_WARNING, $checks['pending_overtime']['severity']);
        $this->assertTrue($checks->has('missing_records'));
        $this->assertTrue($checks->has('cutoff_open'));
    }

    public function test_a_suspension_goes_quiet_once_the_dtr_explains_its_days(): void
    {
        $employee = $this->worker();
        $period = $this->period();

        DisciplinaryAction::create([
            'employee_id' => $employee->id,
            'source' => 'core4',
            'reference' => 'SAF-1',
            'type' => DisciplinaryAction::TYPE_SUSPENSION,
            'reason' => 'Test',
            'effective_from' => '2026-08-04',
            'effective_to' => '2026-08-05',
            'is_unpaid' => true,
        ]);

        $key = fn () => collect(app(PayrollReadinessChecker::class)->check($period)['checks'])->firstWhere('key', 'unserved_suspensions');

        $this->assertNotNull($key());

        $this->record($employee, '2026-08-04', null, null);
        $this->record($employee, '2026-08-05', null, null);

        $this->assertNull($key());
    }
}
