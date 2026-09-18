<?php

namespace Tests\Feature\Timekeeping;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/** Shifts, rest days and holidays — and that Leave reads the same calendar. */
class ScheduleAndCalendarTest extends TimekeepingTestCase
{
    public function test_a_new_schedule_ends_the_one_before_it(): void
    {
        $employee = $this->worker();
        $night = Shift::create([
            'code' => 'NIGHT', 'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00',
            'break_minutes' => 60, 'grace_minutes' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $night->id,
                'rest_days' => [7, 1],
                'effective_from' => '2026-10-01',
            ])
            ->assertSessionHasNoErrors();

        $previous = EmployeeShift::where('shift_id', $this->dayShift->id)->firstOrFail();
        $this->assertSame('2026-09-30', $previous->effective_to->toDateString());

        // September is still computed against days, October against nights.
        $this->assertSame(AttendanceLog::STATUS_LATE, $this->record($employee, '2026-09-15', '22:00', '06:00')->status);
    }

    public function test_leave_is_not_charged_for_holidays_or_rest_days(): void
    {
        $employee = $this->worker();
        EmployeeShift::where('employee_id', $employee->id)->update(['rest_days' => json_encode([3])]); // rests Wednesdays
        Holiday::create(['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day', 'type' => Holiday::TYPE_SPECIAL]);

        // Mon 17 – Sun 23 Aug: Wednesday is a rest day, Friday a holiday.
        $days = app(LeaveService::class)->workingDays($employee->fresh(), Carbon::parse('2026-08-17'), Carbon::parse('2026-08-23'));

        $this->assertSame(5.0, $days);
    }

    public function test_somebody_with_no_schedule_rests_on_weekends(): void
    {
        $employee = Employee::factory()->create();

        $days = app(LeaveService::class)->workingDays($employee, Carbon::parse('2026-08-17'), Carbon::parse('2026-08-23'));

        $this->assertSame(5.0, $days);
    }

    public function test_a_shift_in_use_is_deactivated_not_deleted(): void
    {
        $this->worker();

        $this->actingAs($this->hr())
            ->delete("/hr/timekeeping/shifts/{$this->dayShift->id}")
            ->assertSessionHas('info');

        $this->assertFalse($this->dayShift->refresh()->is_active);
    }

    public function test_only_hr_manages_shifts_and_holidays(): void
    {
        $employee = $this->worker();

        $this->actingAs($employee->user)->get('/hr/timekeeping/shifts')->assertForbidden();
        $this->actingAs($employee->user)
            ->post('/hr/timekeeping/holidays', ['name' => 'X', 'date' => '2026-12-26', 'type' => 'special'])
            ->assertForbidden();

        // Everyone may read the calendar.
        $this->actingAs($employee->user)->get('/hr/timekeeping/holidays')->assertOk();
    }

    public function test_the_same_holiday_cannot_be_added_twice(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => Holiday::TYPE_REGULAR]);

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/holidays', ['name' => 'Christmas Day', 'date' => '2026-12-25', 'type' => 'regular'])
            ->assertSessionHasErrors('name');
    }

    public function test_only_admin_and_super_admin_can_create_shifts(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $hr = $this->hr();

        // Admin can create a shift
        $this->actingAs($admin)
            ->post('/hr/timekeeping/shifts', [
                'code' => 'EVE',
                'name' => 'Evening Shift',
                'start_time' => '17:00',
                'end_time' => '01:00',
                'break_minutes' => 60,
                'grace_minutes' => 15,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shifts', ['code' => 'EVE']);

        // Super Admin can create a shift
        $this->actingAs($superAdmin)
            ->post('/hr/timekeeping/shifts', [
                'code' => 'GRAVE',
                'name' => 'Graveyard Shift',
                'start_time' => '00:00',
                'end_time' => '08:00',
                'break_minutes' => 60,
                'grace_minutes' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shifts', ['code' => 'GRAVE']);

        // HR staff cannot create a shift
        $this->actingAs($hr)
            ->post('/hr/timekeeping/shifts', [
                'code' => 'HRBAD',
                'name' => 'Unauthorized Shift',
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_minutes' => 60,
                'grace_minutes' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('shifts', ['code' => 'HRBAD']);
    }

    public function test_shifts_screen_indicates_whether_user_can_create_shift(): void
    {
        $admin = User::factory()->admin()->create();
        $hr = $this->hr();

        $this->actingAs($admin)
            ->get('/hr/timekeeping/shifts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.createShift', true),
            );

        $this->actingAs($hr)
            ->get('/hr/timekeeping/shifts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.createShift', false),
            );
    }
}
