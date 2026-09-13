<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archiving an employee must not break the records they left behind.
 *
 * Nothing in this system is destroyed by a delete button, so a payslip
 * outlives the employee it belongs to. The trap is that a soft delete hides
 * the row from `belongsTo` while a raw join still returns it: the payroll run
 * screen joins `employees` to sort by surname, so the payslip stayed in the
 * list while `$payslip->employee` came back null.
 *
 * Three screens fataled on that, and the SSS R-3 exported a line carrying
 * money with no person on it — which is worse than omitting the employee,
 * because the filing looks complete and cannot be reconciled.
 */
class ArchivedEmployeeHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payslip_still_names_an_archived_employee(): void
    {
        $payslip = $this->payslipForArchivedEmployee();

        $this->assertTrue($payslip->employee->trashed());
        $this->assertNotNull(
            $payslip->employee->full_name,
            'A payslip that has forgotten whose it is cannot be reconciled.',
        );
    }

    public function test_the_payroll_run_screen_survives_an_archived_employee(): void
    {
        $payslip = $this->payslipForArchivedEmployee();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get("/hr/payroll/runs/{$payslip->payroll_run_id}")
            ->assertOk();
    }

    public function test_the_payslip_screen_survives_an_archived_employee(): void
    {
        $payslip = $this->payslipForArchivedEmployee();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get("/hr/payroll/payslips/{$payslip->id}")
            ->assertOk();
    }

    /**
     * The remittance has to carry the person, not a dash. SSS reconciles an
     * R-3 line by member number; a line with money and no number is one the
     * agency cannot file and cannot explain.
     */
    public function test_a_remittance_line_carries_the_archived_employee(): void
    {
        $payslip = $this->payslipForArchivedEmployee();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $csv = $this->actingAs($admin)
            ->get("/hr/payroll/compliance/export?report=sss&run={$payslip->payroll_run_id}")
            ->streamedContent();

        $this->assertStringNotContainsString('— not on file —', $csv);
        $this->assertStringContainsString('34-1234567-8', $csv);
    }

    private function payslipForArchivedEmployee(): Payslip
    {
        $employee = Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $period = PayrollPeriod::create([
            'name' => 'Aug 1 – 15',
            'start_date' => now()->year.'-08-01',
            'end_date' => now()->year.'-08-15',
            'pay_date' => now()->year.'-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $run = PayrollRun::create([
            'payroll_period_id' => $period->id,
            'run_number' => 'PR-'.now()->year.'-0001',
            'status' => PayrollRun::STATUS_APPROVED,
        ]);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payslip_number' => 'PS-'.$run->run_number.'-'.$employee->id,
            'basic_pay' => 25000,
            'sss_employee' => 875,
            'sss_employer' => 1750,
        ]);

        // The act the whole test is about. Everything above is history now.
        $employee->delete();

        return $payslip->fresh();
    }
}
