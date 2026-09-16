<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    /** 26,100 divides cleanly: daily 1,200 / hourly 150 / per-minute 2.50. */
    private const SALARY = 26100;

    public function test_hr_can_create_a_payroll_period(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/payroll/periods', [
                'name' => 'Aug 1 – 15, 2026',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-15',
                'pay_date' => '2026-08-20',
                'frequency' => 'semi_monthly',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('payroll_periods', ['name' => 'Aug 1 – 15, 2026']);
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        $this->period();

        $this->actingAs($this->hr())
            ->post('/hr/payroll/periods', [
                'name' => 'Overlapping',
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-20',
                'pay_date' => '2026-08-25',
                'frequency' => 'semi_monthly',
            ])
            ->assertSessionHasErrors('start_date');

        $this->assertDatabaseCount('payroll_periods', 1);
    }

    public function test_a_pay_date_cannot_precede_the_period_end(): void
    {
        $this->actingAs($this->hr())
            ->post('/hr/payroll/periods', [
                'name' => 'Backwards',
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-15',
                'pay_date' => '2026-08-10',
                'frequency' => 'semi_monthly',
            ])
            ->assertSessionHasErrors('pay_date');
    }

    public function test_generating_a_run_creates_a_payslip_per_employee(): void
    {
        $period = $this->period();
        Employee::factory()->count(3)->create(['basic_salary' => self::SALARY]);

        $this->actingAs($this->hr())
            ->post("/hr/payroll/periods/{$period->id}/generate")
            ->assertRedirect();

        $run = PayrollRun::firstOrFail();

        $this->assertSame(3, $run->employee_count);
        $this->assertDatabaseCount('payslips', 3);
        $this->assertGreaterThan(0, (float) $run->total_net);
    }

    public function test_employees_without_a_salary_are_skipped(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);
        Employee::factory()->create(['basic_salary' => 0]);

        $this->actingAs($this->hr())->post("/hr/payroll/periods/{$period->id}/generate");

        $this->assertDatabaseCount('payslips', 1);
    }

    public function test_recomputing_replaces_the_draft_rather_than_stacking_runs(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);
        $hr = $this->hr();

        $this->actingAs($hr)->post("/hr/payroll/periods/{$period->id}/generate");
        $this->actingAs($hr)->post("/hr/payroll/periods/{$period->id}/generate");

        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseCount('payslips', 1);
    }

    // --- The figures --------------------------------------------------------

    /**
     * With Time & Attendance removed, a payslip is the basic salary for the
     * period and nothing attendance-derived: no overtime, lateness or absence.
     */
    public function test_a_payslip_is_basic_pay_with_no_attendance_figures(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->actingAs($this->hr())->post("/hr/payroll/periods/{$period->id}/generate");

        $payslip = Payslip::firstOrFail();

        $this->assertEquals(13050.00, (float) $payslip->basic_pay);
        $this->assertEquals(0.0, (float) $payslip->overtime_pay);
        $this->assertEquals(0.0, (float) $payslip->late_deduction);
        $this->assertEquals(0.0, (float) $payslip->absence_deduction);
        $this->assertEquals(
            round((float) $payslip->gross_pay - (float) $payslip->deductions_total, 2),
            (float) $payslip->net_pay,
        );
    }

    public function test_unpaid_leave_is_deducted_but_paid_leave_is_not(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $unpaid = LeaveType::factory()->unpaid()->create();
        $paid = LeaveType::factory()->create();

        LeaveRequest::factory()->approved()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $unpaid->id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-06',
            'days_requested' => 2,
        ]);
        LeaveRequest::factory()->approved()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $paid->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'days_requested' => 2,
        ]);

        $this->actingAs($this->hr())->post("/hr/payroll/periods/{$period->id}/generate");

        $payslip = Payslip::firstOrFail();

        // Only the unpaid two days are charged, at the 1,200 daily rate.
        $this->assertEquals(2.0, (float) $payslip->unpaid_leave_days);
        $this->assertEquals(2400.00, (float) $payslip->unpaid_leave_deduction);
    }

    public function test_allowances_and_loans_reach_the_payslip(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        EmployeeAllowance::create([
            'employee_id' => $employee->id,
            'name' => 'Transportation',
            'amount' => 2000,
            'frequency' => 'monthly',
            'is_taxable' => false,
            'effective_from' => '2026-01-01',
        ]);

        EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => 'sss',
            'principal_amount' => 12000,
            'monthly_amortization' => 1000,
            'outstanding_balance' => 12000,
            'start_date' => '2026-01-01',
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->hr())->post("/hr/payroll/periods/{$period->id}/generate");

        $payslip = Payslip::firstOrFail();

        // A monthly allowance is halved on a semi-monthly run, as is the loan.
        $this->assertEquals(1000.00, (float) $payslip->allowances_total);
        $this->assertEquals(500.00, (float) $payslip->loans_deduction);
    }

    public function test_payslip_lines_are_itemised(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->actingAs($this->hr())->post("/hr/payroll/periods/{$period->id}/generate");

        $codes = Payslip::firstOrFail()->lines->pluck('code')->all();

        $this->assertContains('basic', $codes);
        $this->assertContains('sss', $codes);
        $this->assertContains('philhealth', $codes);
        $this->assertContains('pagibig', $codes);
    }

    // --- Workflow -----------------------------------------------------------

    public function test_the_run_moves_draft_to_approval_to_paid(): void
    {
        $run = $this->generatedRun();
        $hr = User::find($run->processed_by);

        $this->actingAs($hr)->post("/hr/payroll/runs/{$run->id}/submit")->assertRedirect();
        $this->assertSame(PayrollRun::STATUS_FOR_APPROVAL, $run->fresh()->status);

        // A different admin approves — separation of duties.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/hr/payroll/runs/{$run->id}/approve")->assertRedirect();
        $this->assertSame(PayrollRun::STATUS_APPROVED, $run->fresh()->status);

        // Confirming the transfer is a third pair of hands: not the processor.
        $this->actingAs($hr)->post("/hr/payroll/runs/{$run->id}/paid")->assertForbidden();

        $disburser = User::factory()->hrStaff()->create();
        $this->actingAs($disburser)->post("/hr/payroll/runs/{$run->id}/paid")->assertRedirect();
        $this->assertSame(PayrollRun::STATUS_PAID, $run->fresh()->status);
    }

    public function test_the_processor_cannot_approve_their_own_run(): void
    {
        $admin = User::factory()->admin()->create();
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->actingAs($admin)->post("/hr/payroll/periods/{$period->id}/generate");
        $run = PayrollRun::firstOrFail();

        $this->actingAs($admin)->post("/hr/payroll/runs/{$run->id}/submit");

        $this->actingAs($admin)
            ->post("/hr/payroll/runs/{$run->id}/approve")
            ->assertForbidden();

        $this->assertSame(PayrollRun::STATUS_FOR_APPROVAL, $run->fresh()->status);
    }

    public function test_hr_staff_cannot_approve_a_run(): void
    {
        $run = $this->generatedRun();
        $this->actingAs(User::find($run->processed_by))->post("/hr/payroll/runs/{$run->id}/submit");

        $this->actingAs($this->hr())
            ->post("/hr/payroll/runs/{$run->id}/approve")
            ->assertForbidden();
    }

    public function test_an_approved_run_cannot_be_recomputed(): void
    {
        $run = $this->approvedRun();

        $this->actingAs($this->hr())
            ->post("/hr/payroll/periods/{$run->payroll_period_id}/generate")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payroll_runs', 1);
    }

    public function test_approving_applies_loan_amortisations(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => 'company',
            'principal_amount' => 10000,
            'monthly_amortization' => 1000,
            'outstanding_balance' => 10000,
            'start_date' => '2026-01-01',
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);

        $hr = $this->hr();
        $this->actingAs($hr)->post("/hr/payroll/periods/{$period->id}/generate");
        $run = PayrollRun::firstOrFail();

        // Still a draft — the balance must not have moved yet.
        $this->assertEquals(10000.00, (float) $loan->fresh()->outstanding_balance);

        $this->actingAs($hr)->post("/hr/payroll/runs/{$run->id}/submit");
        $this->actingAs(User::factory()->admin()->create())
            ->post("/hr/payroll/runs/{$run->id}/approve");

        // 500 withheld on a semi-monthly run.
        $this->assertEquals(9500.00, (float) $loan->fresh()->outstanding_balance);
    }

    public function test_a_loan_is_closed_once_the_balance_reaches_zero(): void
    {
        $period = $this->period();
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => 'salary_advance',
            'principal_amount' => 400,
            'monthly_amortization' => 1000,
            'outstanding_balance' => 400,
            'start_date' => '2026-01-01',
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);

        $hr = $this->hr();
        $this->actingAs($hr)->post("/hr/payroll/periods/{$period->id}/generate");
        $run = PayrollRun::firstOrFail();
        $this->actingAs($hr)->post("/hr/payroll/runs/{$run->id}/submit");
        $this->actingAs(User::factory()->admin()->create())
            ->post("/hr/payroll/runs/{$run->id}/approve");

        $loan->refresh();

        // The amortisation is capped at the balance, never overshooting.
        $this->assertEquals(0.0, (float) $loan->outstanding_balance);
        $this->assertSame(EmployeeLoan::STATUS_PAID, $loan->status);
    }

    // --- Access -------------------------------------------------------------

    public function test_employees_are_redirected_to_their_own_payslips(): void
    {
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/hr/payroll')
            ->assertRedirect('/hr/payroll/payslips');
    }

    public function test_employees_cannot_run_payroll(): void
    {
        $period = $this->period();
        $user = User::factory()->create();
        Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post("/hr/payroll/periods/{$period->id}/generate")
            ->assertForbidden();
    }

    public function test_an_employee_sees_only_their_own_finalised_payslips(): void
    {
        $run = $this->approvedRun();
        $payslip = $run->payslips()->first();

        $user = User::factory()->create();
        $payslip->employee->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/hr/payroll/payslips')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('payslips.data', 1));

        $this->actingAs($user)
            ->get("/hr/payroll/payslips/{$payslip->id}")
            ->assertOk();
    }

    public function test_a_draft_payslip_is_hidden_from_the_employee(): void
    {
        $run = $this->generatedRun();
        $payslip = $run->payslips()->first();

        $user = User::factory()->create();
        $payslip->employee->update(['user_id' => $user->id]);

        // The figures are still being corrected, so they are not yet theirs to see.
        $this->actingAs($user)
            ->get("/hr/payroll/payslips/{$payslip->id}")
            ->assertForbidden();
    }

    public function test_an_employee_cannot_open_someone_elses_payslip(): void
    {
        $run = $this->approvedRun();
        $payslip = $run->payslips()->first();

        $outsider = User::factory()->create();
        Employee::factory()->create(['user_id' => $outsider->id]);

        $this->actingAs($outsider)
            ->get("/hr/payroll/payslips/{$payslip->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/payroll')->assertRedirect('/login');
    }

    public function test_approved_unpaid_leave_is_still_deducted(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 30000]);
        $period = $this->period();

        LeaveRequest::factory()->approved()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->unpaid()->create()->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-04',
            'days_requested' => 2,
        ]);

        $inputs = app(PayrollService::class)->gatherInputs($period, $employee);

        $this->assertSame(0.0, $inputs['absent_days']);
        $this->assertSame(2.0, $inputs['unpaid_leave_days']);
    }

    public function test_approved_paid_leave_is_not_deducted(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => 30000]);
        $period = $this->period();

        LeaveRequest::factory()->approved()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::factory()->create(['is_paid' => true])->id,
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
            'days_requested' => 1,
        ]);

        $this->assertSame(0.0, app(PayrollService::class)->gatherInputs($period, $employee)['unpaid_leave_days']);
    }

    // --- Helpers ------------------------------------------------------------

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

    private function generatedRun(): PayrollRun
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);

        return app(PayrollService::class)->generate($period, $this->hr());
    }

    private function approvedRun(): PayrollRun
    {
        $run = $this->generatedRun();
        $service = app(PayrollService::class);

        $service->submitForApproval($run);

        return $service->approve($run, User::factory()->admin()->create());
    }
}
