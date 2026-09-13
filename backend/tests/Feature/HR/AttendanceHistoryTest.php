<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_dtr_record_produces_a_visible_history_entry(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr());
        AttendanceLog::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/history')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/History')
                ->has('audits.data', 1)
                ->where('audits.data.0.event', 'created')
                ->where('audits.data.0.employee.employee_number', $employee->employee_number),
            );
    }

    public function test_updating_a_dtr_record_produces_a_readable_change_summary(): void
    {
        $this->actingAs($this->hr());
        $log = AttendanceLog::factory()->create([
            'time_in' => now()->setTime(8, 0),
            'status' => AttendanceLog::STATUS_PRESENT,
        ]);

        $log->update(['status' => AttendanceLog::STATUS_LATE, 'late_minutes' => 30]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/history?event=updated')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audits.data', 1)
                ->where('audits.data.0.event', 'updated')
                ->has('audits.data.0.changes'),
            );
    }

    public function test_filtering_by_event_narrows_the_list(): void
    {
        $this->actingAs($this->hr());
        $log = AttendanceLog::factory()->create();
        $log->update(['status' => AttendanceLog::STATUS_LATE, 'late_minutes' => 15]);

        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/history?event=created')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audits.data', 1)
                ->where('audits.data.0.event', 'created'),
            );
    }

    public function test_an_employee_cannot_view_the_history_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/hr/timekeeping/history')
            ->assertForbidden();
    }

    public function test_a_supervisor_cannot_view_the_history_page(): void
    {
        $user = User::factory()->supervisor()->create();

        $this->actingAs($user)
            ->get('/hr/timekeeping/history')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/timekeeping/history')->assertRedirect('/login');
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
