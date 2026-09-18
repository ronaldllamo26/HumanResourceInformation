<?php

namespace Tests\Feature\HR;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\PayrollPeriod;
use App\Models\Shift;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TimekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The Reports screen, and the two files it hands over. */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_opens_the_masterlist_by_default(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())
            ->get('/hr/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Reports/Index')
                ->where('report', 'employees')
                ->where('result.title', 'Employee Masterlist')
                ->where('result.rows.0.name', $employee->full_name)
                ->has('reports', 5));
    }

    public function test_the_attendance_report_reads_the_time_records(): void
    {
        $employee = $this->workerWithADay();

        $this->actingAs($this->hr())
            ->get('/hr/reports?report=attendance&from=2026-09-01&to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.rows.0.days_worked', 1)
                ->where('result.rows.0.late_minutes', 25)
                ->where('result.totals.late_minutes', 25));

        $this->assertSame(1, Employee::where('id', $employee->id)->count());
    }

    public function test_the_csv_carries_its_heading_columns_and_control_total(): void
    {
        $this->workerWithADay();

        $csv = $this->actingAs($this->hr())
            ->get('/hr/reports/export/csv?report=attendance&from=2026-09-01&to=2026-09-15')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Attendance Summary', $csv);
        $this->assertStringContainsString('Sep 1, 2026 – Sep 15, 2026', $csv);
        $this->assertStringContainsString('Late (min)', $csv);
        $this->assertStringContainsString('1 employee(s)', $csv);
    }

    /** A real file, not the browser's print dialog — a report is handed to somebody else. */
    public function test_the_pdf_is_a_pdf(): void
    {
        $this->workerWithADay();

        $response = $this->actingAs($this->hr())
            ->get('/hr/reports/export/pdf?report=attendance&from=2026-09-01&to=2026-09-15')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment; filename=attendance-', $response->headers->get('content-disposition'));
    }

    public function test_a_download_is_recorded_as_an_export(): void
    {
        $hr = $this->hr();
        Employee::factory()->create();

        $this->actingAs($hr)->get('/hr/reports/export/csv?report=employees')->assertOk();

        $row = AuditLog::where('event', 'exported')->latest('id')->firstOrFail();

        $this->assertSame($hr->id, $row->user_id);
        $this->assertSame('employees', $row->new_values['report'] ?? null);
        $this->assertSame('csv', $row->new_values['format'] ?? null);
    }

    public function test_the_payroll_register_reads_finalised_runs_only(): void
    {
        $period = PayrollPeriod::create([
            'name' => 'Sep 1 – 15, 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'pay_date' => '2026-09-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        Employee::factory()->create(['basic_salary' => 26100]);
        $hr = $this->hr();
        $service = app(PayrollService::class);
        $run = $service->generate($period, $hr);

        // A draft is still being corrected, so it is not a register.
        $this->actingAs($hr)
            ->get("/hr/reports?report=payroll&period={$period->id}")
            ->assertInertia(fn (Assert $page) => $page->where('result.row_count', 0));

        $service->submitForApproval($run);
        $service->approve($run, User::factory()->admin()->create());

        $this->actingAs($hr)
            ->get("/hr/reports?report=payroll&period={$period->id}")
            ->assertInertia(fn (Assert $page) => $page->where('result.row_count', 1));
    }

    public function test_the_client_billing_summary_says_whether_the_client_confirmed(): void
    {
        $period = PayrollPeriod::create([
            'name' => 'Sep 1 – 15, 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-15',
            'pay_date' => '2026-09-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $client = Client::create(['code' => 'ACME', 'name' => 'Acme Logistics', 'is_active' => true]);
        $this->workerWithADay(['employment_category' => 'external', 'client_id' => $client->id]);

        $this->actingAs($this->hr())
            ->get("/hr/reports?report=client_billing&period={$period->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('result.rows.0.client', 'Acme Logistics')
                ->where('result.rows.0.headcount', 1)
                ->where('result.rows.0.timesheet', 'not prepared'));
    }

    /** A report is many people's records in one file; a supervisor's own screens are the scoped ones. */
    public function test_reports_are_hr_and_admin_only(): void
    {
        foreach ([User::factory()->supervisor()->create(), User::factory()->create(['role' => User::ROLE_EMPLOYEE])] as $user) {
            $this->actingAs($user)->get('/hr/reports')->assertForbidden();
            $this->actingAs($user)->get('/hr/reports/export/csv?report=employees')->assertForbidden();
        }
    }

    public function test_an_unknown_format_is_not_a_report(): void
    {
        $this->actingAs($this->hr())->get('/hr/reports/export/docx?report=employees')->assertNotFound();
    }

    public function test_the_excel_export_returns_spreadsheet(): void
    {
        $this->workerWithADay();

        $response = $this->actingAs($this->hr())
            ->get('/hr/reports/export/excel?report=attendance&from=2026-09-01&to=2026-09-15')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.ms-excel; charset=utf-8');

        $this->assertStringContainsString('urn:schemas-microsoft-com:office:spreadsheet', $response->getContent());
        $this->assertStringContainsString('Attendance Summary', $response->getContent());
    }

    /** No report carries what a stolen copy would be worth stealing. */
    public function test_government_numbers_and_bank_details_are_in_no_report(): void
    {
        Employee::factory()->create([
            'sss_number' => '3412345678',
            'tin' => '123456789012',
            'bank_account_number' => '001234567890',
        ]);

        $csv = $this->actingAs($this->hr())
            ->get('/hr/reports/export/csv?report=employees')
            ->streamedContent();

        foreach (['3412345678', '123456789012', '001234567890'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 09:00'));
    }

    private function workerWithADay(array $attributes = []): Employee
    {
        $shift = Shift::firstOrCreate(['code' => 'DAY'], [
            'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00',
            'break_minutes' => 60, 'grace_minutes' => 10, 'is_active' => true,
        ]);

        $employee = Employee::factory()->create(['basic_salary' => 26100, ...$attributes]);

        EmployeeShift::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'rest_days' => [6, 7],
            'effective_from' => '2026-01-01',
        ]);

        app(TimekeepingService::class)->record($employee, Carbon::parse('2026-09-14'), '08:25', '17:00');

        return $employee;
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
