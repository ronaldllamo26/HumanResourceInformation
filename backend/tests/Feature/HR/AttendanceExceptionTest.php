<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AttendanceExceptionScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_time_out_on_a_past_day_is_flagged(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Exceptions')
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_MISSING_PUNCH)
                ->where('exceptions.0.severity', 'critical'),
            );
    }

    public function test_a_missing_time_out_today_is_not_flagged(): void
    {
        // Still clocked in — normal, not an exception.
        AttendanceLog::factory()->create([
            'log_date' => now()->toDateString(),
            'time_in' => now()->setTime(8, 0),
            'time_out' => null,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_excessive_lateness_is_flagged(): void
    {
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_EXCESSIVE_LATE),
            );
    }

    public function test_ordinary_lateness_under_the_threshold_is_not_flagged(): void
    {
        AttendanceLog::factory()->late(15)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_excessive_overtime_is_flagged(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDay()->toDateString(),
            'overtime_minutes' => 300, // 5 hours, above the 4-hour config threshold
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_EXCESSIVE_OVERTIME),
            );
    }

    public function test_frequent_lateness_is_flagged_as_a_pattern(): void
    {
        $employee = Employee::factory()->create();

        // Three late days, none individually over the per-day threshold —
        // all inside the current month, the default filter range.
        foreach ([1, 2, 3] as $daysAgo) {
            AttendanceLog::factory()->late(20)->create([
                'employee_id' => $employee->id,
                'log_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_FREQUENT_LATE)
                ->where('exceptions.0.employee_id', $employee->id),
            );
    }

    public function test_frequent_absence_is_flagged_as_a_pattern(): void
    {
        $employee = Employee::factory()->create();

        foreach ([1, 2, 3] as $daysAgo) {
            AttendanceLog::factory()->absent()->create([
                'employee_id' => $employee->id,
                'log_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(function (Assert $page) {
                $types = collect($page->toArray()['props']['exceptions'])->pluck('type');

                // Four findings, saying two different things: each day is an
                // AWOL on its own — nothing was filed for any of them — and
                // the three together are a pattern.
                $this->assertSame(3, $types->filter(
                    fn ($type) => $type === AttendanceExceptionScanner::TYPE_AWOL,
                )->count());

                $this->assertTrue($types->contains(
                    AttendanceExceptionScanner::TYPE_FREQUENT_ABSENCE,
                ));
            });
    }

    public function test_an_absence_covered_by_approved_leave_is_not_awol(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay();

        AttendanceLog::factory()->absent()->create([
            'employee_id' => $employee->id,
            'log_date' => $date->toDateString(),
        ]);

        $this->approvedLeave($employee, $date);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(function (Assert $page) {
                $exceptions = collect($page->toArray()['props']['exceptions']);

                $this->assertFalse($exceptions->pluck('type')->contains(
                    AttendanceExceptionScanner::TYPE_AWOL,
                ));

                /*
                 * Still a finding, but a different one: the leave was approved
                 * after the day was keyed, so the DTR row is stale. A warning
                 * rather than an error — payroll already ignores it, so the
                 * money is right and only the record reads wrong.
                 */
                $stale = $exceptions->firstWhere(
                    'type',
                    AttendanceExceptionScanner::TYPE_UNRECORDED_LEAVE,
                );

                $this->assertNotNull($stale);
                $this->assertSame('warning', $stale['severity']);
            });
    }

    public function test_a_day_marked_on_leave_raises_nothing(): void
    {
        $employee = Employee::factory()->create();

        // The row already says what happened, so there is nothing to
        // reconcile — the cross-check only ever looks at absences.
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->subDay()->toDateString(),
            'status' => AttendanceLog::STATUS_ON_LEAVE,
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_a_clean_record_produces_no_exceptions(): void
    {
        AttendanceLog::factory()->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 0));
    }

    public function test_the_summary_counts_by_severity(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 2)
                ->where('summary.critical', 1)
                ->where('summary.warning', 1),
            );
    }

    public function test_filtering_by_type_narrows_the_list(): void
    {
        AttendanceLog::factory()->create([
            'log_date' => now()->subDays(2)->toDateString(),
            'time_in' => now()->subDays(2)->setTime(8, 0),
            'time_out' => null,
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/exceptions?type='.AttendanceExceptionScanner::TYPE_MISSING_PUNCH)
            ->assertInertia(fn (Assert $page) => $page
                ->has('exceptions', 1)
                ->where('exceptions.0.type', AttendanceExceptionScanner::TYPE_MISSING_PUNCH),
            );
    }

    public function test_an_employee_only_sees_exceptions_for_their_own_records(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        AttendanceLog::factory()->late(90)->create([
            'employee_id' => $own->id,
            'log_date' => now()->subDay()->toDateString(),
        ]);
        AttendanceLog::factory()->late(90)->create(['log_date' => now()->subDay()->toDateString()]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/exceptions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('exceptions', 1));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping/exceptions')->assertRedirect('/login');
    }

    /**
     * Every test here files attendance a day or three back and then reads the
     * screen with no date filter, which defaults to the current month
     * (AttendanceExceptionController). On the 1st of a month "yesterday" is in
     * the previous one, so the records land outside the range and the whole
     * class goes red — for one day, on a system that is otherwise correct.
     *
     * It went unnoticed because it needs the calendar to catch it. Pinning
     * "now" to mid-month removes the calendar from the equation; the tests
     * still exercise the default range, they just no longer depend on which
     * day the suite is run.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(9, 0));
    }

    /** An approved leave covering one day, of a type that is paid. */
    private function approvedLeave(Employee $employee, Carbon $date): void
    {
        LeaveRequest::factory()
            ->approved()
            ->on($date->toDateString())
            ->create([
                'employee_id' => $employee->id,
                'leave_type_id' => LeaveType::factory()->create(['name' => 'Vacation Leave'])->id,
            ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
