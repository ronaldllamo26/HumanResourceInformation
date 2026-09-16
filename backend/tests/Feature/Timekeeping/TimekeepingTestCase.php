<?php

namespace Tests\Feature\Timekeeping;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\PayrollPeriod;
use App\Models\Shift;
use App\Models\User;
use App\Services\TimekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shared setup for the Time & Attendance tests. "Now" is pinned, because
 * several rules compare against today (no future records, a period that has
 * started) and a test that passes on the 16th and fails on the 1st is worse
 * than none.
 */
abstract class TimekeepingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 12:00'));

        $this->dayShift = Shift::create([
            'code' => 'DAY', 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00',
            'break_minutes' => 60, 'grace_minutes' => 10, 'is_active' => true,
        ]);
    }

    protected function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    /** An employee with a login and the day shift, resting Saturday and Sunday. */
    protected function worker(array $attributes = []): Employee
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee = Employee::factory()->create(['user_id' => $user->id, 'basic_salary' => 26100, ...$attributes]);

        EmployeeShift::create([
            'employee_id' => $employee->id,
            'shift_id' => $this->dayShift->id,
            'rest_days' => [6, 7],
            'effective_from' => '2026-01-01',
        ]);

        return $employee;
    }

    protected function period(string $start = '2026-08-01', string $end = '2026-08-15'): PayrollPeriod
    {
        return PayrollPeriod::create([
            'name' => Carbon::parse($start)->format('M j').' – '.Carbon::parse($end)->format('j, Y'),
            'start_date' => $start,
            'end_date' => $end,
            'pay_date' => Carbon::parse($end)->addDays(5)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    protected function record(Employee $employee, string $date, ?string $in, ?string $out)
    {
        return app(TimekeepingService::class)->record($employee, Carbon::parse($date), $in, $out);
    }
}
