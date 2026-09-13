<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The cutoff DTR sheet — a fortnight of attendance for a department or a
 * client, encoded as statuses and saved in one pass.
 *
 * Time is pinned to mid-period in setUp for the reason AttendanceExceptionTest
 * pins it: the screen opens on the cutoff covering *today*, so a test written
 * against a real clock passes for a fortnight and fails on the 16th.
 */
class PeriodAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sheet_opens_on_the_payroll_period_covering_today(): void
    {
        $this->period();
        $this->employee();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/period')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Period')
                ->where('period.start_date', '2026-08-01')
                ->where('period.end_date', '2026-08-15')
                ->where('period.is_payroll_period', true)
                // One column per day of the cutoff, one row per employee.
                ->has('days', 15)
                ->has('rows', 1)
                ->has('rows.0.cells', 15)
                ->where('can.manage', true),
            );
    }

    public function test_a_cutoff_with_no_payroll_period_falls_back_to_the_calendar_half_month(): void
    {
        $this->employee();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/period')
            ->assertOk()
            // Refusing to open would invert the order of the work: attendance
            // is encoded while the fortnight runs, and the period is created
            // when it is time to pay.
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.is_payroll_period', false)
                ->where('period.start_date', '2026-08-01')
                ->where('period.end_date', '2026-08-15')
                ->has('days', 15),
            );
    }

    public function test_saving_statuses_writes_one_dtr_row_a_day_with_no_punches(): void
    {
        $this->period();
        $employee = $this->employee();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/period', [
                'from' => '2026-08-01',
                'to' => '2026-08-15',
                'cells' => [
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => 'present'],
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-04', 'status' => 'absent'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, AttendanceLog::where('employee_id', $employee->id)->count());

        $present = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('log_date', '2026-08-03')
            ->firstOrFail();

        $this->assertSame('present', $present->status);
        $this->assertNull($present->time_in);
        $this->assertNull($present->time_out);
        // Every derived figure is zero, because there are no punches to derive
        // one from. Payroll deducts from absences, not from these.
        $this->assertSame(0, $present->late_minutes);
        $this->assertSame(0, $present->overtime_minutes);
        $this->assertSame('manual', $present->source);
    }

    public function test_a_day_whose_status_did_not_change_is_not_rewritten(): void
    {
        $this->period();
        $employee = $this->employee();

        $this->actingAs($this->hr())->post('/hr/timekeeping/period', [
            'from' => '2026-08-01',
            'to' => '2026-08-15',
            'cells' => [
                ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => 'present'],
            ],
        ]);

        $writes = AuditLog::where('auditable_type', AttendanceLog::class)->count();

        // Re-submitting the same sheet. AttendanceLog is Auditable, so a save
        // that wrote unconditionally would leave a second row behind every
        // time somebody opened the screen and pressed Save.
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/period', [
                'from' => '2026-08-01',
                'to' => '2026-08-15',
                'cells' => [
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => 'present'],
                ],
            ])
            ->assertSessionHas('success', 'Nothing to save — no day on the sheet changed.');

        $this->assertSame($writes, AuditLog::where('auditable_type', AttendanceLog::class)->count());
    }

    public function test_a_day_already_carrying_punches_is_never_overwritten(): void
    {
        $this->period();
        $employee = $this->employee();

        $clocked = AttendanceLog::create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => '2026-08-03 17:00:00',
            'status' => 'present',
            'source' => 'biometric',
        ]);

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/period', [
                'from' => '2026-08-01',
                'to' => '2026-08-15',
                'cells' => [
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => 'absent'],
                ],
            ])
            // Said out loud rather than swallowed: a save that quietly did
            // less than it appeared to is how somebody comes to believe a
            // correction was made.
            ->assertSessionHas('error');

        $clocked->refresh();

        $this->assertSame('present', $clocked->status);
        $this->assertNotNull($clocked->time_in);
    }

    public function test_the_sheet_hands_a_punched_day_over_locked(): void
    {
        $this->period();
        $employee = $this->employee();

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'time_in' => '2026-08-03 08:00:00',
            'time_out' => '2026-08-03 17:00:00',
            'status' => 'present',
            'source' => 'biometric',
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/period')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.cells.2026-08-03.locked', true)
                ->where('rows.0.cells.2026-08-03.time_in', '08:00')
                ->where('rows.0.cells.2026-08-04.locked', false),
            );
    }

    public function test_blanking_a_status_removes_the_day(): void
    {
        $this->period();
        $employee = $this->employee();

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'log_date' => '2026-08-03',
            'status' => 'absent',
            'source' => 'manual',
        ]);

        $this->actingAs($this->hr())->post('/hr/timekeeping/period', [
            'from' => '2026-08-01',
            'to' => '2026-08-15',
            'cells' => [
                ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => null],
            ],
        ]);

        $this->assertSame(0, AttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_a_day_outside_the_cutoff_is_refused(): void
    {
        $this->period();
        $employee = $this->employee();

        $this->actingAs($this->hr())->post('/hr/timekeeping/period', [
            'from' => '2026-08-01',
            'to' => '2026-08-15',
            'cells' => [
                // Inside the payload, outside the sheet on screen.
                ['employee_id' => $employee->id, 'log_date' => '2026-07-20', 'status' => 'absent'],
            ],
        ]);

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_a_future_day_cannot_be_encoded(): void
    {
        $this->period();
        $employee = $this->employee();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/period', [
                'from' => '2026-08-01',
                'to' => '2026-08-15',
                'cells' => [
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-14', 'status' => 'present'],
                ],
            ])
            ->assertSessionHasErrors('cells');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_scope_is_re_derived_at_the_write(): void
    {
        $this->period();
        $stranger = $this->employee();

        $supervisorUser = User::factory()->supervisor()->create();
        Employee::factory()->create([
            'user_id' => $supervisorUser->id,
            'date_hired' => '2026-01-01',
        ]);

        // The browser may post any id it likes; which employees a user may
        // write time for is not the browser's answer to give.
        $this->actingAs($supervisorUser)->post('/hr/timekeeping/period', [
            'from' => '2026-08-01',
            'to' => '2026-08-15',
            'cells' => [
                ['employee_id' => $stranger->id, 'log_date' => '2026-08-03', 'status' => 'present'],
            ],
        ]);

        $this->assertSame(0, AttendanceLog::where('employee_id', $stranger->id)->count());
    }

    public function test_only_hr_may_save_a_sheet(): void
    {
        $this->period();
        $employee = $this->employee();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post('/hr/timekeeping/period', [
                'from' => '2026-08-01',
                'to' => '2026-08-15',
                'cells' => [
                    ['employee_id' => $employee->id, 'log_date' => '2026-08-03', 'status' => 'present'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_the_calendar_suggests_holidays_and_rest_days_without_saving_them(): void
    {
        $this->period();
        $employee = $this->employee();

        Holiday::create([
            'name' => 'Ninoy Aquino Day',
            'date' => '2026-08-03',
            'type' => Holiday::TYPE_REGULAR,
            'is_nationwide' => true,
        ]);

        // Monday to Friday, so 8 August (a Saturday) is a rest day.
        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => Shift::factory()->create()->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5],
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/period')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.cells.2026-08-03.suggested', 'holiday')
                ->where('rows.0.cells.2026-08-08.suggested', 'rest_day')
                ->where('rows.0.cells.2026-08-08.status', null)
                ->where('days.7.holiday', null)
                ->where('days.2.holiday', 'Ninoy Aquino Day'),
            );

        // A suggestion is drawn, never applied — nothing is stored until a
        // person submits the sheet.
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_the_client_filter_narrows_the_sheet(): void
    {
        $this->period();

        $deployed = $this->employee();
        $internal = $this->employee();

        $client = Client::create([
            'code' => 'MFL',
            'name' => 'Metro Fleet Logistics',
            'is_active' => true,
        ]);

        $deployed->update([
            'employment_category' => 'external',
            'client_id' => $client->id,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/period?client_id='.$client->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.employee_id', $deployed->id),
            );

        $this->assertNotSame($deployed->id, $internal->id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-10 09:00:00');
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'name' => 'Aug 1 – 15, 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-15',
            'pay_date' => '2026-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::factory()->create(['date_hired' => '2026-01-01']);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
