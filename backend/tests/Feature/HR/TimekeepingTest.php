<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimekeepingTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_lists_one_row_per_employee(): void
    {
        // Three days for one person is one row carrying three, not three rows.
        $employee = Employee::factory()->create();

        foreach ([0, 1, 2] as $offset) {
            AttendanceLog::factory()->create([
                'employee_id' => $employee->id,
                'log_date' => now()->startOfMonth()->addDays($offset)->toDateString(),
            ]);
        }

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Index')
                ->has('rows.data', 1)
                // Pagination reads meta.links; rows.links is the
                // {first,last,prev,next} object and crashes <Pagination>.
                ->has('rows.meta.links')
                ->where('rows.data.0.days_present', 3)
                ->where('summary.records', 3)
                ->where('can.manage', true),
            );
    }

    public function test_somebody_with_no_attendance_is_still_listed_at_zero(): void
    {
        /*
         * The point of the screen. A person with nothing recorded for the
         * cutoff is the answer to "who came in", not a row to leave out —
         * which is what grouping the logs themselves would have done.
         */
        Employee::factory()->create();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.days_present', 0),
            );
    }

    public function test_the_range_defaults_to_the_current_month(): void
    {
        $employee = Employee::factory()->create();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->startOfMonth()->toDateString(),
        ]);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.days_present', 1));
    }

    public function test_an_employee_screen_shows_their_days_and_a_calendar(): void
    {
        $employee = Employee::factory()->create();

        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-09-01',
        ]);

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/employee/{$employee->id}?from=2026-09-01&to=2026-09-15")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Employee')
                ->where('employee.id', $employee->id)
                ->has('days.data', 1)
                ->where('summary.present', 1)
                // 1–15 September 2026 spans three Monday-to-Sunday weeks.
                ->has('weeks', 3)
                ->has('weeks.0.days', 7),
            );
    }

    public function test_the_calendar_totals_each_week(): void
    {
        $employee = Employee::factory()->create();

        // Two days in the first calendar week (Aug 31 – Sep 6), one in the
        // second. The 31st is outside the cutoff and must not be counted.
        foreach ([['2026-08-31', 8], ['2026-09-01', 8], ['2026-09-02', 7], ['2026-09-08', 6]] as [$date, $worked]) {
            AttendanceLog::factory()->create([
                'employee_id' => $employee->id,
                'log_date' => $date,
                'hours_worked' => $worked,
                'status' => AttendanceLog::STATUS_PRESENT,
            ]);
        }

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/employee/{$employee->id}?from=2026-09-01&to=2026-09-15")
            ->assertInertia(fn (Assert $page) => $page
                // 8 + 7 — the 31st is in the row but outside the cutoff, and a
                // week total the payslip will not match is worse than none.
                ->where('weeks.0.hours_worked', 15)
                ->where('weeks.0.days_present', 2)
                ->where('weeks.1.hours_worked', 6),
            );
    }

    public function test_the_calendar_marks_days_outside_the_cutoff_rather_than_dropping_them(): void
    {
        $employee = Employee::factory()->create();

        // 1 September 2026 is a Tuesday, so the first row starts on Monday
        // the 31st of August — outside the cutoff, and still a cell.
        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/employee/{$employee->id}?from=2026-09-01&to=2026-09-15")
            ->assertInertia(fn (Assert $page) => $page
                ->where('weeks.0.starts_on', '2026-08-31')
                ->where('weeks.0.days.0.date', '2026-08-31')
                ->where('weeks.0.days.0.in_range', false)
                ->where('weeks.0.days.1.date', '2026-09-01')
                ->where('weeks.0.days.1.in_range', true),
            );
    }

    public function test_the_employee_screen_is_gated_on_seeing_that_employee(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $stranger = Employee::factory()->create();

        // The id is in the URL; whose DTR may be read is not the URL's answer.
        $this->actingAs($user)
            ->get("/hr/timekeeping/employee/{$stranger->id}")
            ->assertForbidden();
    }

    public function test_hr_can_record_a_time_entry_and_figures_are_derived(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();
        $date = now()->subDay()->toDateString();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => $employee->id,
                'log_date' => $date,
                'shift_id' => $shift->id,
                'time_in' => '08:35',
                'time_out' => '17:00',
            ])
            ->assertRedirect();

        $log = AttendanceLog::firstOrFail();

        $this->assertSame(35, $log->late_minutes);
        $this->assertSame(AttendanceLog::STATUS_LATE, $log->status);
        $this->assertEquals(7.42, (float) $log->hours_worked);
    }

    public function test_recording_the_same_day_twice_updates_rather_than_duplicates(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();
        $date = now()->subDay()->toDateString();
        $hr = $this->hr();

        $payload = [
            'employee_id' => $employee->id,
            'log_date' => $date,
            'shift_id' => $shift->id,
            'time_in' => '08:00',
            'time_out' => '17:00',
        ];

        $this->actingAs($hr)->post('/hr/timekeeping', $payload);
        $this->actingAs($hr)->post('/hr/timekeeping', [...$payload, 'time_out' => '19:00']);

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame(120, AttendanceLog::first()->overtime_minutes);
    }

    public function test_the_shift_is_resolved_from_the_employee_schedule(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();

        // 2026-03-10 is a Tuesday (ISO weekday 2).
        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5],
        ]);

        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-10',
            'time_in' => '08:00',
            'time_out' => '17:00',
        ]);

        $this->assertSame($shift->id, AttendanceLog::firstOrFail()->shift_id);
    }

    public function test_a_scheduled_employee_off_roster_is_marked_rest_day(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();

        EmployeeSchedule::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5], // weekdays only
        ]);

        // 2026-03-15 is a Sunday.
        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-15',
        ]);

        $this->assertSame(AttendanceLog::STATUS_REST_DAY, AttendanceLog::firstOrFail()->status);
    }

    public function test_a_holiday_is_classified_as_such(): void
    {
        Holiday::create(['name' => 'Test Holiday', 'date' => '2026-03-12', 'type' => 'regular']);
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => '2026-03-12',
        ]);

        $this->assertSame(AttendanceLog::STATUS_HOLIDAY, AttendanceLog::firstOrFail()->status);
    }

    public function test_future_dated_records_are_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->addWeek()->toDateString(),
                'time_in' => '08:00',
            ])
            ->assertSessionHasErrors('log_date');
    }

    public function test_a_time_out_without_a_time_in_is_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->subDay()->toDateString(),
                'time_out' => '17:00',
            ])
            ->assertSessionHasErrors('time_in');
    }

    public function test_malformed_times_are_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping', [
                'employee_id' => Employee::factory()->create()->id,
                'log_date' => now()->subDay()->toDateString(),
                'time_in' => '8am',
            ])
            ->assertSessionHasErrors('time_in');
    }

    public function test_employees_only_see_their_own_time_records(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);

        AttendanceLog::factory()->on(now()->startOfMonth()->toDateString())->create(['employee_id' => $own->id]);
        AttendanceLog::factory()->count(4)->on(now()->startOfMonth()->toDateString())->create();

        $this->actingAs($user)
            ->get('/hr/timekeeping')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows.data', 1)
                ->where('rows.data.0.employee_id', $own->id)
                ->where('can.manage', false),
            );
    }

    public function test_a_supervisor_sees_their_direct_reports(): void
    {
        $user = User::factory()->supervisor()->create();
        $supervisor = Employee::factory()->create(['user_id' => $user->id]);
        $report = Employee::factory()->create(['supervisor_id' => $supervisor->id]);
        $date = now()->startOfMonth()->toDateString();

        AttendanceLog::factory()->on($date)->create(['employee_id' => $report->id]);
        AttendanceLog::factory()->on($date)->create(['employee_id' => $supervisor->id]);
        AttendanceLog::factory()->count(3)->on($date)->create();

        // The supervisor and their one report — the three strangers are not
        // rows here even though they have attendance in the same range.
        $this->actingAs($user)
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
    }

    public function test_non_hr_roles_cannot_record_time(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/hr/timekeeping', [
            'employee_id' => $employee->id,
            'log_date' => now()->subDay()->toDateString(),
            'time_in' => '08:00',
            'time_out' => '17:00',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_only_hr_can_delete_a_record(): void
    {
        $log = AttendanceLog::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->delete("/hr/timekeeping/{$log->id}")->assertForbidden();
        $this->actingAs($this->hr())->delete("/hr/timekeeping/{$log->id}")->assertRedirect();

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping')->assertRedirect('/login');
    }

    public function test_the_summary_totals_the_filtered_range(): void
    {
        $date = now()->startOfMonth()->toDateString();
        AttendanceLog::factory()->count(2)->on($date)->create();
        AttendanceLog::factory()->on($date)->absent()->create();
        AttendanceLog::factory()->on($date)->late(20)->create();

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.records', 4)
                ->where('summary.absent', 1)
                ->where('summary.late', 1),
            );
    }

    public function test_the_api_exposes_the_same_records(): void
    {
        AttendanceLog::factory()->count(3)->on(now()->toDateString())->create();
        $hr = $this->hr();

        $this->actingAs($hr)
            ->getJson('/api/v1/attendance?from='.now()->startOfMonth()->toDateString())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'log_date', 'status', 'hours_worked']]]);

        $this->actingAs($hr)
            ->getJson('/api/v1/attendance/summary')
            ->assertOk()
            ->assertJsonPath('data.records', 3);
    }

    public function test_the_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/attendance')->assertUnauthorized();
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
