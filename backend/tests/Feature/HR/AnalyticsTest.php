<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/analytics/workforce')->assertRedirect('/login');
        $this->get('/hr/analytics/attendance')->assertRedirect('/login');
        $this->get('/hr/employees/documents/batch')->assertRedirect('/login');
    }

    public function test_employees_are_forbidden(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)
            ->get('/hr/analytics/workforce')
            ->assertForbidden();

        $this->actingAs($employee)
            ->get('/hr/analytics/attendance')
            ->assertForbidden();

        $this->actingAs($employee)
            ->get('/hr/employees/documents/batch')
            ->assertForbidden();
    }

    public function test_admin_can_view_workforce_analytics(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Employee::factory()->count(3)->create(['status' => 'active']);

        $this->actingAs($admin)
            ->get('/hr/analytics/workforce')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Analytics/Workforce')
                ->has('summary')
                ->has('monthlyMovement')
                ->has('tenureBrackets')
                ->has('clientDeployments')
                ->has('departmentHeadcounts')
                ->where('summary.active_count', 3),
            );
    }

    public function test_admin_can_view_attendance_analytics(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $emp = Employee::factory()->create(['status' => 'active']);

        AttendanceLog::factory()->create([
            'employee_id' => $emp->id,
            'log_date' => now()->toDateString(),
            'status' => AttendanceLog::STATUS_PRESENT,
        ]);

        OvertimeRequest::create([
            'employee_id' => $emp->id,
            'date' => now()->toDateString(),
            'start_time' => now()->setTime(18, 0),
            'end_time' => now()->setTime(20, 0),
            'hours' => 2.0,
            'reason' => 'Project rush',
            'status' => OvertimeRequest::STATUS_APPROVED,
        ]);

        $this->actingAs($admin)
            ->get('/hr/analytics/attendance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Analytics/Attendance')
                ->has('summary')
                ->has('dayOfWeekPatterns')
                ->has('monthlyOtTrend')
                ->has('departmentAttendance')
                ->where('summary.present_count', 1),
            );
    }

    public function test_admin_can_view_ai_batch_scanner_screen(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/hr/employees/documents/batch')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/DocumentBatch')
                ->has('documentTypes')
                ->has('maxFiles'),
            );
    }
}
