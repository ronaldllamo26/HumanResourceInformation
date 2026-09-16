<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\ComplianceReportBuilder;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const SALARY = 26100;

    public function test_hr_sees_the_sss_remittance_for_an_approved_run(): void
    {
        $this->approvedRun();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Payroll/Compliance')
                ->where('report', ComplianceReportBuilder::REPORT_SSS)
                ->has('rows', 1)
                ->has('rows.0.employee_share')
                ->has('rows.0.employer_share'),
            );
    }

    public function test_the_totals_add_up_the_employee_and_employer_shares(): void
    {
        $this->approvedRun();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance')
            ->assertInertia(function (Assert $page) {
                $rows = $page->toArray()['props']['rows'];
                $totals = $page->toArray()['props']['totals'];

                // assertEquals, not assertSame: a whole-peso total survives the
                // JSON round trip as an int, not the float it started as.
                $this->assertEquals(
                    round($rows[0]['employee_share'] + $rows[0]['employer_share'], 2),
                    $totals['total'],
                );
            });
    }

    public function test_the_bir_alphalist_reports_tax_and_taxable_income(): void
    {
        $this->approvedRun();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance?report='.ComplianceReportBuilder::REPORT_BIR)
            ->assertInertia(fn (Assert $page) => $page
                ->where('report', ComplianceReportBuilder::REPORT_BIR)
                ->has('rows.0.tax')
                ->has('rows.0.taxable')
                ->has('rows.0.gross')
                // The identifier column relabels itself per report.
                ->where('columns.identifier', 'TIN'),
            );
    }

    public function test_taxable_income_is_gross_less_the_employee_contributions(): void
    {
        $this->approvedRun();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance?report='.ComplianceReportBuilder::REPORT_BIR)
            ->assertInertia(function (Assert $page) {
                $row = $page->toArray()['props']['rows'][0];

                $this->assertSame(
                    round($row['gross'] - $row['contributions'], 2),
                    $row['taxable'],
                );
            });
    }

    public function test_an_employee_without_a_government_number_is_flagged(): void
    {
        $this->approvedRun(['sss_number' => null]);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance')
            ->assertInertia(fn (Assert $page) => $page
                ->has('missingIds', 1)
                ->where('rows.0.has_id', false),
            );
    }

    public function test_an_employee_with_a_number_is_not_flagged(): void
    {
        $this->approvedRun(['sss_number' => '34-1234567-8']);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance')
            ->assertInertia(fn (Assert $page) => $page
                ->has('missingIds', 0)
                // Masked on screen; the CSV export carries the full number.
                ->where('rows.0.identifier', '••••••5678'),
            );
    }

    public function test_a_draft_run_is_not_reportable(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);
        app(PayrollService::class)->generate($period, $this->hr());

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance')
            ->assertInertia(fn (Assert $page) => $page
                ->has('runs', 0)
                ->has('rows', 0)
                ->where('selectedRun', null),
            );
    }

    public function test_an_unknown_report_falls_back_to_sss(): void
    {
        $this->approvedRun();

        $this->actingAs($this->hr())
            ->get('/hr/payroll/compliance?report=nonsense')
            ->assertInertia(fn (Assert $page) => $page
                ->where('report', ComplianceReportBuilder::REPORT_SSS),
            );
    }

    public function test_the_export_streams_csv_with_a_control_total(): void
    {
        $run = $this->approvedRun();

        $response = $this->actingAs($this->hr())
            ->get("/hr/payroll/compliance/export?report=sss&run={$run->id}");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('SSS No.', $csv);
        $this->assertStringContainsString('TOTAL', $csv);
    }

    public function test_the_export_refuses_a_run_that_is_not_approved(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);
        $draft = app(PayrollService::class)->generate($period, $this->hr());

        $this->actingAs($this->hr())
            ->get("/hr/payroll/compliance/export?report=sss&run={$draft->id}")
            ->assertNotFound();
    }

    public function test_an_employee_cannot_reach_the_compliance_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/hr/payroll/compliance')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/payroll/compliance')->assertRedirect('/login');
    }

    private function approvedRun(array $employee = []): PayrollRun
    {
        $period = $this->period();

        Employee::factory()->create([
            'basic_salary' => self::SALARY,
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789-000',
            ...$employee,
        ]);

        $service = app(PayrollService::class);
        $run = $service->generate($period, $this->hr());
        $service->submitForApproval($run);

        return $service->approve($run, User::factory()->admin()->create());
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }

    private function period(): PayrollPeriod
    {
        return PayrollPeriod::create([
            'name' => 'Aug 1 – 15, 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-15',
            'pay_date' => '2026-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }
}
