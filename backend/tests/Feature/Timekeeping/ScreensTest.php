<?php

namespace Tests\Feature\Timekeeping;

use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\TimeCorrection;
use App\Models\User;
use App\Services\ClientTimesheetService;
use Inertia\Testing\AssertableInertia as Assert;

/** Every Time & Attendance screen renders, with data on it, for the people who open it. */
class ScreensTest extends TimekeepingTestCase
{
    public function test_every_screen_renders_for_hr(): void
    {
        $hr = $this->hr();
        $period = $this->period('2026-09-01', '2026-09-15');
        $client = Client::create(['code' => 'ACME', 'name' => 'Acme Logistics', 'is_active' => true]);
        $employee = $this->worker(['employment_category' => 'external', 'client_id' => $client->id]);

        $this->record($employee, '2026-09-14', '08:20', '18:30');
        Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => Holiday::TYPE_REGULAR]);
        OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => '2026-09-14', 'hours' => 1.5, 'reason' => 'x', 'status' => 'pending']);
        TimeCorrection::create(['employee_id' => $employee->id, 'work_date' => '2026-09-14', 'time_in' => '2026-09-14 08:00:00', 'reason' => 'x', 'status' => 'pending']);
        $sheet = app(ClientTimesheetService::class)->prepare($client, $period, $hr);

        $screens = [
            '/hr/timekeeping' => 'HR/Timekeeping/Records',
            '/hr/timekeeping/shifts' => 'HR/Timekeeping/Shifts',
            '/hr/timekeeping/holidays?year=2026' => 'HR/Timekeeping/Holidays',
            '/hr/timekeeping/overtime' => 'HR/Timekeeping/Overtime',
            '/hr/timekeeping/corrections' => 'HR/Timekeeping/Corrections',
            '/hr/timekeeping/cutoffs' => 'HR/Timekeeping/Cutoffs',
            "/hr/timekeeping/cutoffs/{$period->id}" => 'HR/Timekeeping/CutoffShow',
            "/hr/timekeeping/client-timesheets?period={$period->id}" => 'HR/Timekeeping/ClientTimesheets',
            "/hr/timekeeping/client-timesheets/{$sheet->id}" => 'HR/Timekeeping/ClientTimesheetShow',
        ];

        foreach ($screens as $url => $component) {
            $this->actingAs($hr)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }

        $this->actingAs($hr)->get('/hr/timekeeping/overtime')
            ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.recorded_overtime_minutes', 90));
        $this->actingAs($hr)->get('/hr/timekeeping/corrections')
            ->assertInertia(fn (Assert $page) => $page->where('corrections.data.0.current.time_in', '08:20'));
        $this->assertSame(ClientTimesheet::STATUS_DRAFT, $sheet->status);
    }

    public function test_an_employee_can_open_their_own_screens(): void
    {
        $employee = $this->worker();

        foreach (['/hr/timekeeping', '/hr/timekeeping/holidays', '/hr/timekeeping/overtime', '/hr/timekeeping/corrections'] as $url) {
            $this->actingAs($employee->user)->get($url)->assertOk();
        }
    }

    public function test_the_sidebar_screens_exist_for_a_supervisor(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)->get('/hr/timekeeping/overtime')->assertOk();
        $this->actingAs($supervisor)->get('/hr/timekeeping/shifts')->assertForbidden();
    }
}
