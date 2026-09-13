<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_lists_shifts_and_schedules(): void
    {
        $shift = Shift::factory()->create();
        EmployeeSchedule::create([
            'employee_id' => Employee::factory()->create()->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3, 4, 5],
        ]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/schedules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Schedules')
                ->has('shifts', 1)
                ->has('schedules.data', 1)
                ->where('can.manage', true),
            );
    }

    public function test_hr_can_create_a_shift(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/shifts', [
                'name' => 'Early Dispatch',
                'start_time' => '06:00',
                'end_time' => '15:00',
                'break_minutes' => 60,
                'grace_period_minutes' => 10,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shifts', ['name' => 'Early Dispatch', 'grace_period_minutes' => 10]);
    }

    public function test_shift_names_must_be_unique(): void
    {
        Shift::factory()->create(['name' => 'Day Shift']);

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/shifts', [
                'name' => 'Day Shift',
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'grace_period_minutes' => 15,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_a_shift_cannot_start_and_end_at_the_same_time(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/shifts', [
                'name' => 'Zero Length',
                'start_time' => '08:00',
                'end_time' => '08:00',
                'break_minutes' => 0,
                'grace_period_minutes' => 0,
            ])
            ->assertSessionHasErrors('end_time');
    }

    public function test_a_shift_in_use_is_deactivated_rather_than_deleted(): void
    {
        $shift = Shift::factory()->create();
        AttendanceLog::factory()->create(['shift_id' => $shift->id]);

        $this->actingAs($this->hr())
            ->delete("/hr/timekeeping/shifts/{$shift->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'is_active' => false]);
    }

    public function test_an_unused_shift_is_deleted(): void
    {
        $shift = Shift::factory()->create();

        $this->actingAs($this->hr())
            ->delete("/hr/timekeeping/shifts/{$shift->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_hr_can_assign_a_schedule(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/schedules', [
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'effective_from' => '2026-01-01',
                'days_of_week' => [1, 2, 3, 4, 5],
            ])
            ->assertRedirect();

        $schedule = EmployeeSchedule::firstOrFail();

        $this->assertSame([1, 2, 3, 4, 5], $schedule->days_of_week);
    }

    public function test_overlapping_schedules_are_rejected(): void
    {
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->create();
        $hr = $this->hr();

        $payload = [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1, 2, 3],
        ];

        $this->actingAs($hr)->post('/hr/timekeeping/schedules', $payload)->assertRedirect();
        $this->actingAs($hr)->post('/hr/timekeeping/schedules', $payload)
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('employee_schedules', 1);
    }

    public function test_at_least_one_working_day_is_required(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/schedules', [
                'employee_id' => Employee::factory()->create()->id,
                'shift_id' => Shift::factory()->create()->id,
                'effective_from' => '2026-01-01',
                'days_of_week' => [],
            ])
            ->assertSessionHasErrors('days_of_week');
    }

    public function test_non_hr_roles_cannot_manage_shifts_or_schedules(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);
        $shift = Shift::factory()->create();

        $this->actingAs($user)->post('/hr/timekeeping/shifts', [
            'name' => 'Sneaky Shift',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'grace_period_minutes' => 15,
        ])->assertForbidden();

        $this->actingAs($user)->delete("/hr/timekeeping/shifts/{$shift->id}")->assertForbidden();

        $this->actingAs($user)->post('/hr/timekeeping/schedules', [
            'employee_id' => Employee::factory()->create()->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-01-01',
            'days_of_week' => [1],
        ])->assertForbidden();
    }

    public function test_non_hr_roles_see_the_screen_read_only(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/schedules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
