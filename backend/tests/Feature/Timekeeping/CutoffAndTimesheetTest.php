<?php

namespace Tests\Feature\Timekeeping;

use App\Models\AttendanceCutoff;
use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

class CutoffAndTimesheetTest extends TimekeepingTestCase
{
    public function test_a_cutoff_with_a_missing_time_out_cannot_close(): void
    {
        $period = $this->period();
        $this->record($this->worker(), '2026-08-04', '08:00', null);

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/cutoffs/{$period->id}/close")
            ->assertSessionHasErrors('cutoff');

        $this->assertDatabaseMissing('attendance_cutoffs', ['status' => 'closed']);
    }

    public function test_hr_closes_a_clean_cutoff_and_only_an_admin_reopens_it_with_a_reason(): void
    {
        $period = $this->period();
        $this->record($this->worker(), '2026-08-04', '08:00', '17:00');

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/cutoffs/{$period->id}/close")
            ->assertSessionHasNoErrors();

        $this->assertSame(AttendanceCutoff::STATUS_CLOSED, AttendanceCutoff::firstOrFail()->status);

        $this->actingAs($this->hr())
            ->post("/hr/timekeeping/cutoffs/{$period->id}/reopen", ['reason' => 'Client sent a late correction'])
            ->assertForbidden();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post("/hr/timekeeping/cutoffs/{$period->id}/reopen", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post("/hr/timekeeping/cutoffs/{$period->id}/reopen", ['reason' => 'Client sent a late correction'])
            ->assertSessionHasNoErrors();

        $this->assertSame(AttendanceCutoff::STATUS_OPEN, AttendanceCutoff::firstOrFail()->status);
    }

    public function test_the_cutoff_screen_shows_what_payroll_will_read(): void
    {
        $period = $this->period();
        $employee = $this->worker();
        $this->record($employee, '2026-08-04', '08:30', '17:00');
        $this->record($employee, '2026-08-05', null, null);

        $this->actingAs($this->hr())
            ->get("/hr/timekeeping/cutoffs/{$period->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Timekeeping/CutoffShow')
                ->where('rows.0.days_worked', 1)
                ->where('rows.0.late_minutes', 30)
                ->where('rows.0.absent_days', 1));
    }

    public function test_a_client_timesheet_is_prepared_sent_and_confirmed(): void
    {
        $period = $this->period();
        $client = Client::create(['code' => 'ACME', 'name' => 'Acme Logistics', 'is_active' => true]);
        $deployed = $this->worker(['employment_category' => 'external', 'client_id' => $client->id]);
        $this->worker(); // internal staff are not on a client's sheet
        $this->record($deployed, '2026-08-04', '08:00', '17:00');

        $hr = $this->hr();

        $this->actingAs($hr)
            ->post('/hr/timekeeping/client-timesheets', ['client_id' => $client->id, 'payroll_period_id' => $period->id])
            ->assertRedirect();

        $sheet = ClientTimesheet::with('lines')->firstOrFail();
        $this->assertCount(1, $sheet->lines);
        $this->assertEquals(1, $sheet->lines->first()->days_worked);

        $this->actingAs($hr)->post("/hr/timekeeping/client-timesheets/{$sheet->id}/send")->assertSessionHasNoErrors();

        $this->actingAs($hr)
            ->post("/hr/timekeeping/client-timesheets/{$sheet->id}/answer", ['decision' => 'dispute', 'confirmed_by_name' => 'Ana Cruz'])
            ->assertSessionHasErrors('client_remarks');

        $this->actingAs($hr)
            ->post("/hr/timekeeping/client-timesheets/{$sheet->id}/answer", ['decision' => 'confirm', 'confirmed_by_name' => 'Ana Cruz'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sheet->refresh()->isConfirmed());

        // What the client signed cannot be silently regenerated.
        $this->actingAs($hr)
            ->post('/hr/timekeeping/client-timesheets', ['client_id' => $client->id, 'payroll_period_id' => $period->id])
            ->assertSessionHasErrors('timesheet');
    }

    public function test_cutoffs_and_timesheets_are_hr_only(): void
    {
        $employee = $this->worker();
        $period = $this->period();

        $this->actingAs($employee->user)->get('/hr/timekeeping/cutoffs')->assertForbidden();
        $this->actingAs($employee->user)->post("/hr/timekeeping/cutoffs/{$period->id}/close")->assertForbidden();
        $this->actingAs($employee->user)->get('/hr/timekeeping/client-timesheets')->assertForbidden();
    }
}
