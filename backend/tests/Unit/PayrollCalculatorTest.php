<?php

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use App\Services\StatutoryContributions;
use Tests\TestCase;

/**
 * A monthly salary of 26,100 is used throughout because it divides cleanly:
 * daily 1,200 / hourly 150 / per-minute 2.50. Every expectation below is
 * therefore checkable by hand.
 */
class PayrollCalculatorTest extends TestCase
{
    private const SALARY = 26100.0;

    private PayrollCalculator $calculator;

    public function test_rates_derive_from_the_monthly_salary(): void
    {
        $rates = $this->calculator->rates(self::SALARY);

        $this->assertSame(1200.0, $rates['daily']);   // 26,100 x 12 / 261
        $this->assertSame(150.0, $rates['hourly']);   // daily / 8
        $this->assertSame(2.5, $rates['per_minute']);
    }

    public function test_a_clean_semi_monthly_period_pays_half_the_salary(): void
    {
        $result = $this->compute();

        $this->assertSame(13050.0, $result['payslip']['basic_pay']);
        $this->assertSame(13050.0, $result['payslip']['gross_pay']);
    }

    public function test_overtime_is_paid_at_a_twenty_five_percent_premium(): void
    {
        $result = $this->compute(['overtime_hours' => 10]);

        // 150 x 1.25 x 10
        $this->assertSame(1875.0, $result['payslip']['overtime_pay']);
    }

    public function test_night_differential_is_ten_percent_of_the_hourly_rate(): void
    {
        $result = $this->compute(['night_diff_hours' => 8]);

        $this->assertSame(120.0, $result['payslip']['night_diff_pay']);
    }

    public function test_tardiness_is_charged_per_minute(): void
    {
        $result = $this->compute(['late_minutes' => 60, 'undertime_minutes' => 30]);

        $this->assertSame(150.0, $result['payslip']['late_deduction']);
        $this->assertSame(75.0, $result['payslip']['undertime_deduction']);
    }

    public function test_absences_and_unpaid_leave_are_charged_at_the_daily_rate(): void
    {
        $result = $this->compute(['absent_days' => 1, 'unpaid_leave_days' => 2]);

        $this->assertSame(1200.0, $result['payslip']['absence_deduction']);
        $this->assertSame(2400.0, $result['payslip']['unpaid_leave_deduction']);
    }

    public function test_statutory_contributions_are_halved_on_a_semi_monthly_run(): void
    {
        $payslip = $this->compute()['payslip'];

        // MSC 26,000 -> 1,300 monthly employee share -> 650 semi-monthly.
        $this->assertSame(650.0, $payslip['sss_employee']);
        $this->assertSame(326.25, $payslip['philhealth_employee']);
        $this->assertSame(100.0, $payslip['pagibig_employee']);
    }

    public function test_a_full_period_reconciles_end_to_end(): void
    {
        $payslip = $this->compute([
            'overtime_hours' => 10,
            'night_diff_hours' => 8,
            'late_minutes' => 60,
            'absent_days' => 1,
        ])['payslip'];

        // 13,050 + 1,875 + 120
        $this->assertSame(15045.0, $payslip['gross_pay']);

        // Tardiness 150 + absence 1,200 + statutory 1,076.25 + tax 330.26
        $this->assertSame(2756.51, $payslip['deductions_total']);
        $this->assertSame(12288.49, $payslip['net_pay']);

        // Net must always equal gross less total deductions.
        $this->assertSame(
            round($payslip['gross_pay'] - $payslip['deductions_total'], 2),
            $payslip['net_pay'],
        );
    }

    public function test_non_taxable_allowances_are_excluded_from_taxable_income(): void
    {
        $taxable = $this->compute([
            'allowances' => [['label' => 'Transportation', 'amount' => 2000, 'taxable' => true]],
        ])['payslip'];

        $nonTaxable = $this->compute([
            'allowances' => [['label' => 'Transportation', 'amount' => 2000, 'taxable' => false]],
        ])['payslip'];

        $this->assertSame($taxable['gross_pay'], $nonTaxable['gross_pay']);
        $this->assertGreaterThan($nonTaxable['withholding_tax'], $taxable['withholding_tax']);
    }

    public function test_time_not_worked_reduces_taxable_income(): void
    {
        $clean = $this->compute()['payslip'];
        $absent = $this->compute(['absent_days' => 3])['payslip'];

        $this->assertLessThan($clean['withholding_tax'], $absent['withholding_tax']);
    }

    public function test_a_low_earner_pays_no_withholding_tax(): void
    {
        $payslip = $this->calculator->compute([
            'monthly_salary' => 15000,
            'pay_frequency' => 'semi_monthly',
        ])['payslip'];

        $this->assertSame(0.0, $payslip['withholding_tax']);
        // Statutory contributions still apply.
        $this->assertGreaterThan(0, $payslip['sss_employee']);
    }

    public function test_loans_and_other_deductions_reduce_net_pay(): void
    {
        $payslip = $this->compute([
            'loans' => [['label' => 'SSS Salary Loan', 'amount' => 750]],
            'other_deductions' => [['label' => 'Uniform', 'amount' => 250]],
        ])['payslip'];

        $this->assertSame(750.0, $payslip['loans_deduction']);
        $this->assertSame(250.0, $payslip['other_deductions']);
    }

    public function test_a_monthly_frequency_pays_the_whole_salary(): void
    {
        $payslip = $this->calculator->compute([
            'monthly_salary' => self::SALARY,
            'pay_frequency' => 'monthly',
        ])['payslip'];

        $this->assertSame(26100.0, $payslip['basic_pay']);
        $this->assertSame(1300.0, $payslip['sss_employee']);
    }

    // --- Payslip lines ----------------------------------------------------

    public function test_lines_itemise_earnings_and_deductions(): void
    {
        $lines = $this->compute([
            'overtime_hours' => 10,
            'allowances' => [['label' => 'Meal Allowance', 'amount' => 1000]],
            'loans' => [['label' => 'Pag-IBIG Loan', 'amount' => 500]],
        ])['lines'];

        $codes = array_column($lines, 'code');

        $this->assertContains('basic', $codes);
        $this->assertContains('overtime', $codes);
        $this->assertContains('allowance', $codes);
        $this->assertContains('sss', $codes);
        $this->assertContains('loan', $codes);
    }

    public function test_zero_value_lines_are_left_off_the_payslip(): void
    {
        $lines = $this->compute()['lines'];
        $codes = array_column($lines, 'code');

        // No overtime was worked, so no overtime line.
        $this->assertNotContains('overtime', $codes);
        $this->assertNotContains('late', $codes);
        $this->assertContains('basic', $codes);
    }

    public function test_the_lines_sum_to_the_payslip_totals(): void
    {
        $result = $this->compute([
            'overtime_hours' => 6,
            'late_minutes' => 45,
            'allowances' => [['label' => 'Meal', 'amount' => 800]],
            'loans' => [['label' => 'Company Loan', 'amount' => 400]],
        ]);

        $earnings = array_sum(array_column(
            array_filter($result['lines'], fn ($line) => $line['type'] === 'earning'),
            'amount',
        ));

        $deductions = array_sum(array_column(
            array_filter($result['lines'], fn ($line) => $line['type'] === 'deduction'),
            'amount',
        ));

        $this->assertSame($result['payslip']['gross_pay'], round($earnings, 2));
        $this->assertSame($result['payslip']['deductions_total'], round($deductions, 2));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new PayrollCalculator(new StatutoryContributions);
    }

    /** @return array{payslip: array<string, mixed>, lines: array<int, array<string, mixed>>} */
    private function compute(array $overrides = []): array
    {
        return $this->calculator->compute([
            'monthly_salary' => self::SALARY,
            'pay_frequency' => 'semi_monthly',
            ...$overrides,
        ]);
    }
}
