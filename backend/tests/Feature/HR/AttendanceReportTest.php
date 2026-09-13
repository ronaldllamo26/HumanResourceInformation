<?php

namespace Tests\Feature\HR;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_reports_screen_redirects_to_records(): void
    {
        // The per-employee summary is the Records screen itself now. Old links
        // land somewhere useful rather than on a 404, and carry their range.
        $this->actingAs($this->hr())
            ->get('/hr/timekeeping/reports?from=2026-03-01&to=2026-03-31')
            ->assertRedirect('/hr/timekeeping?from=2026-03-01&to=2026-03-31');
    }

    public function test_the_export_covers_the_range_it_was_given(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Elena', 'last_name' => 'Marquez']);
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-03-10',
        ]);
        // Outside the range, so it must not reach the file.
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => '2026-05-10',
        ]);

        $response = $this->actingAs($this->hr())
            ->get('/hr/timekeeping/export?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // fputcsv quotes headers containing spaces — still valid CSV.
        $this->assertStringContainsString('"Employee Number",Employee,Department', $csv);
        $this->assertStringContainsString('Elena', $csv);
        $this->assertStringContainsString($employee->employee_number, $csv);

        /*
         * One day, not two. The export used to resolve its own range from a
         * `period` name, which meant the button on a screen showing 1-15 could
         * hand back the whole month without either saying so.
         */
        $line = collect(explode("\n", trim($csv)))->last();
        $this->assertStringContainsString(',1,', $line);
    }

    public function test_the_export_defaults_to_the_current_month(): void
    {
        $employee = Employee::factory()->create();
        AttendanceLog::factory()->create([
            'employee_id' => $employee->id,
            'log_date' => now()->startOfMonth()->toDateString(),
        ]);

        $csv = $this->actingAs($this->hr())
            ->get('/hr/timekeeping/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($employee->employee_number, $csv);
    }

    public function test_employees_only_see_themselves_in_the_export(): void
    {
        $user = User::factory()->create();
        $own = Employee::factory()->create(['user_id' => $user->id]);
        $date = now()->startOfMonth()->toDateString();

        AttendanceLog::factory()->create(['employee_id' => $own->id, 'log_date' => $date]);
        $others = Employee::factory()->count(3)->create();

        foreach ($others as $other) {
            AttendanceLog::factory()->create([
                'employee_id' => $other->id,
                'log_date' => $date,
            ]);
        }

        $csv = $this->actingAs($user)
            ->get('/hr/timekeeping/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($own->employee_number, $csv);

        foreach ($others as $other) {
            $this->assertStringNotContainsString($other->employee_number, $csv);
        }
    }

    // --- Bulk import -----------------------------------------------------

    public function test_hr_can_import_a_csv_of_time_records(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},08:00,17:00\n";

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertSame('biometric', AttendanceLog::firstOrFail()->source);
    }

    public function test_bad_rows_are_reported_without_aborting_the_batch(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},08:00,17:00\n"
            ."PPM-9999-9999,{$date},08:00,17:00\n"          // unknown employee
            ."{$employee->employee_number},not-a-date,08:00,17:00\n"
            ."{$employee->employee_number},{$date},99:99,17:00\n";

        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHas('importErrors', fn ($errors) => count($errors) === 3);

        // The one good row still landed.
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_a_file_missing_required_columns_is_rejected(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', "name,hours\nJuan,8\n"),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_imported_times_are_computed_like_manual_entries(): void
    {
        $employee = Employee::factory()->create();
        $date = now()->subDay()->toDateString();

        $csv = "employee_number,date,time_in,time_out\n"
            ."{$employee->employee_number},{$date},9:05,17:00\n"; // single-digit hour

        $this->actingAs($this->hr())->post('/hr/timekeeping/import', [
            'file' => UploadedFile::fake()->createWithContent('dtr.csv', $csv),
        ]);

        $log = AttendanceLog::firstOrFail();

        // No schedule assigned, so no shift and therefore no lateness — but the
        // hours still come through, which proves the time parsed.
        $this->assertEquals(7.92, (float) $log->hours_worked);
    }

    public function test_non_hr_roles_cannot_import(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/hr/timekeeping/import', [
                'file' => UploadedFile::fake()->createWithContent('dtr.csv', "employee_number,date\n"),
            ])
            ->assertForbidden();
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
