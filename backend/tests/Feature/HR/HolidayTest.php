<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_calendar_lists_the_selected_year(): void
    {
        $this->holiday('2026-12-25', 'Christmas Day');
        $this->holiday('2027-01-01', "New Year's Day");

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/holidays?year=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/Holidays')
                ->has('holidays', 1)
                ->where('holidays.0.name', 'Christmas Day')
                ->where('holidays.0.day_of_week', 'Friday'),
            );
    }

    public function test_hr_can_add_a_holiday(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/holidays', [
                'name' => 'Araw ng Kagitingan',
                'date' => '2027-04-09',
                'type' => Holiday::TYPE_REGULAR,
                'is_nationwide' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('holidays', [
            'name' => 'Araw ng Kagitingan',
            'type' => Holiday::TYPE_REGULAR,
        ]);
    }

    public function test_the_same_holiday_cannot_be_recorded_twice_on_one_date(): void
    {
        $this->holiday('2027-12-25', 'Christmas Day');

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/holidays', [
                'name' => 'Christmas Day',
                'date' => '2027-12-25',
                'type' => Holiday::TYPE_REGULAR,
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(1, Holiday::count());
    }

    public function test_two_different_holidays_may_share_a_date(): void
    {
        $this->holiday('2027-11-01', "All Saints' Day");

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/holidays', [
                'name' => 'Special Proclamation',
                'date' => '2027-11-01',
                'type' => Holiday::TYPE_SPECIAL,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Holiday::count());
    }

    public function test_hr_can_edit_a_holiday(): void
    {
        $holiday = $this->holiday('2027-08-30', 'National Heroes Day');

        $this->actingAs($this->hr())
            ->put("/hr/timekeeping/holidays/{$holiday->id}", [
                'name' => 'National Heroes Day',
                'date' => '2027-08-30',
                'type' => Holiday::TYPE_SPECIAL,
            ])
            ->assertRedirect();

        $this->assertSame(Holiday::TYPE_SPECIAL, $holiday->fresh()->type);
    }

    public function test_an_unused_holiday_can_be_removed(): void
    {
        $holiday = $this->holiday('2027-06-12', 'Independence Day');

        $this->actingAs($this->hr())
            ->delete("/hr/timekeeping/holidays/{$holiday->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);
    }

    public function test_a_holiday_with_attendance_recorded_is_kept(): void
    {
        $holiday = $this->holiday('2026-08-31', 'National Heroes Day');

        AttendanceLog::factory()->create([
            'employee_id' => Employee::factory()->create()->id,
            'log_date' => '2026-08-31',
        ]);

        $this->actingAs($this->hr())
            ->delete("/hr/timekeeping/holidays/{$holiday->id}")
            ->assertSessionHas('error');

        // Removing it would leave that day's attendance classified against a
        // rule that no longer exists.
        $this->assertDatabaseHas('holidays', ['id' => $holiday->id]);
    }

    public function test_the_screen_warns_when_next_year_has_no_holidays(): void
    {
        $this->holiday(now()->year.'-12-25', 'Christmas Day');

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/holidays')
            ->assertInertia(fn (Assert $page) => $page
                ->where('nextYear.year', now()->year + 1)
                ->where('nextYear.count', 0),
            );
    }

    public function test_the_warning_clears_once_next_year_is_set_up(): void
    {
        $this->holiday((now()->year + 1).'-01-01', "New Year's Day");

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/holidays')
            ->assertInertia(fn (Assert $page) => $page->where('nextYear.count', 1));
    }

    public function test_an_employee_may_view_but_not_manage(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/hr/timekeeping/holidays')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $this->actingAs($user)
            ->post('/hr/timekeeping/holidays', [
                'name' => 'Fake Holiday',
                'date' => '2027-03-01',
                'type' => Holiday::TYPE_REGULAR,
            ])
            ->assertForbidden();
    }

    public function test_a_supervisor_cannot_manage_holidays(): void
    {
        $this->actingAs(User::factory()->supervisor()->create())
            ->delete('/hr/timekeeping/holidays/'.$this->holiday('2027-05-01', 'Labor Day')->id)
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping/holidays')->assertRedirect('/login');
    }

    private function holiday(string $date, string $name): Holiday
    {
        return Holiday::create([
            'name' => $name,
            'date' => $date,
            'type' => Holiday::TYPE_REGULAR,
            'is_nationwide' => true,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
