<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ThirteenthMonthTest extends TestCase
{
    use RefreshDatabase;

    private const SALARY = 26100;

    private ?PayrollPeriod $period = null;

    public function test_it_divides_basic_salary_earned_by_twelve(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        // Two periods at 13,050 each, nothing deducted.
        $this->payslip($employee, basic: 13050);
        $this->payslip($employee, basic: 13050);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Payroll/ThirteenthMonth')
                ->has('rows', 1)
                ->where('rows.0.basic_earned', 26100)
                ->where('rows.0.amount', 2175), // 26,100 ÷ 12
            );
    }

    /**
     * The rule is "basic salary *earned*". PayrollCalculator writes the full
     * period salary into basic_pay and takes time not worked off separately,
     * so those deductions have to come back out here.
     */
    public function test_time_not_worked_reduces_the_basic_salary_earned(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->payslip($employee, basic: 13050, absence: 1200, late: 300);
        $this->payslip($employee, basic: 13050, unpaidLeave: 2400, undertime: 150);

        // 26,100 − 1,200 − 300 − 2,400 − 150 = 22,050
        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.basic_earned', 22050)
                ->where('rows.0.amount', 1837.5),
            );
    }

    public function test_a_mid_year_hire_is_pro_rated_by_having_fewer_payslips(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        // Only one period paid all year.
        $this->payslip($employee, basic: 13050);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.periods_paid', 1)
                ->where('rows.0.amount', 1087.5), // 13,050 ÷ 12
            );
    }

    public function test_overtime_and_allowances_are_excluded(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->payslip($employee, basic: 13050, extra: [
            'overtime_pay' => 5000,
            'night_diff_pay' => 2000,
            'holiday_pay' => 3000,
            'allowances_total' => 4000,
            'gross_pay' => 27050,
        ]);

        // Only basic counts — PD 851 is computed on basic salary, not gross.
        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.basic_earned', 13050)
                ->where('rows.0.amount', 1087.5),
            );
    }

    public function test_deductions_beyond_basic_pay_never_produce_a_negative(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->payslip($employee, basic: 1000, absence: 5000);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.basic_earned', 0)
                ->where('rows.0.amount', 0),
            );
    }

    public function test_a_draft_run_is_not_counted(): void
    {
        $period = $this->period();
        Employee::factory()->create(['basic_salary' => self::SALARY]);

        // Generated but never approved — still being corrected.
        app(PayrollService::class)->generate($period, $this->hr());

        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
    }

    public function test_each_employee_gets_one_row(): void
    {
        $a = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $b = Employee::factory()->create(['basic_salary' => self::SALARY]);

        $this->payslip($a, basic: 13050);
        $this->payslip($a, basic: 13050);
        $this->payslip($b, basic: 13050);

        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 2)
                ->where('totals.employees', 2)
                ->where('totals.amount', 3262.5), // 2,175 + 1,087.50
            );
    }

    public function test_the_screen_reports_the_december_deadline(): void
    {
        $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month?year='.now()->year)
            ->assertInertia(fn (Assert $page) => $page
                ->where('deadline.date', now()->year.'-12-24'),
            );
    }

    public function test_the_export_streams_csv_with_a_control_total(): void
    {
        $employee = Employee::factory()->create(['basic_salary' => self::SALARY]);
        $this->payslip($employee, basic: 13050);

        $response = $this->actingAs($this->hr())
            ->get('/hr/payroll/13th-month/export?year='.now()->year);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('13th Month Pay', $csv);
        $this->assertStringContainsString('TOTAL', $csv);
        $this->assertStringContainsString($employee->employee_number, $csv);
    }

    public function test_an_employee_cannot_view_the_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/hr/payroll/13th-month')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/hr/payroll/13th-month')->assertRedirect('/login');
    }

    /** A payslip on an approved run, so the screen counts it. */
    private function payslip(
        Employee $employee,
        float $basic,
        float $absence = 0,
        float $late = 0,
        float $undertime = 0,
        float $unpaidLeave = 0,
        array $extra = [],
    ): Payslip {
        $run = PayrollRun::create([
            'payroll_period_id' => $this->period()->id,
            'run_number' => 'PR-'.now()->year.'-'.str_pad((string) (PayrollRun::count() + 1), 4, '0', STR_PAD_LEFT),
            'status' => PayrollRun::STATUS_APPROVED,
        ]);

        return Payslip::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'payslip_number' => 'PS-'.$run->run_number.'-'.$employee->id,
            'basic_pay' => $basic,
            'absence_deduction' => $absence,
            'late_deduction' => $late,
            'undertime_deduction' => $undertime,
            'unpaid_leave_deduction' => $unpaidLeave,
            ...$extra,
        ]);
    }

    /**
     * Held on the instance rather than looked up each call: `firstOrCreate`
     * matches on exact column equality, and these date-cast columns store as
     * "Y-m-d 00:00:00", so a "Y-m-d" lookup misses and inserts a duplicate —
     * the same gotcha the holidays screen hit.
     */
    private function period(): PayrollPeriod
    {
        return $this->period ??= PayrollPeriod::create([
            'name' => 'Aug 1 – 15',
            'start_date' => now()->year.'-08-01',
            'end_date' => now()->year.'-08-15',
            'pay_date' => now()->year.'-08-20',
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
