<?php

namespace Tests\Unit;

use App\Services\FinalPayCalculator;
use Tests\TestCase;

/**
 * Database-free, like the other calculators — every rule is checked without a
 * payroll run standing behind it.
 *
 * A monthly salary of 26,100 gives a daily rate of 26,100 × 12 ÷ 261 = 1,200.
 */
class FinalPayCalculatorTest extends TestCase
{
    private const SALARY = 26100;

    private const DAILY = 1200.0;

    public function test_it_sums_the_four_components(): void
    {
        $result = $this->compute([
            'days_unpaid' => 5,                  // 6,000
            'basic_earned_this_year' => 156600,  // ÷ 12 = 13,050
            'convertible_leave_days' => 4,       // 4,800
            'loan_balance' => 3000,
        ]);

        $this->assertSame(6000.0, $result['unpaid_salary']);
        $this->assertSame(13050.0, $result['thirteenth_month']);
        $this->assertSame(4800.0, $result['leave_conversion']);
        $this->assertSame(23850.0, $result['gross']);
        $this->assertSame(3000.0, $result['loan_deduction']);
        $this->assertSame(20850.0, $result['net_final_pay']);
    }

    /** The daily rate has to match PayrollCalculator's, or the two disagree. */
    public function test_the_daily_rate_matches_payrolls_derivation(): void
    {
        $result = $this->compute(['days_unpaid' => 1]);

        $this->assertSame(self::DAILY, $result['unpaid_salary']);
    }

    public function test_13th_month_is_basic_earned_divided_by_twelve(): void
    {
        $result = $this->compute(['basic_earned_this_year' => 78300]);

        $this->assertSame(6525.0, $result['thirteenth_month']);
    }

    /**
     * A loan cannot take more than the settlement holds — the remainder is a
     * debt to collect, not a negative cheque to hand someone.
     */
    public function test_a_loan_larger_than_the_settlement_is_capped(): void
    {
        $result = $this->compute([
            'basic_earned_this_year' => 12000,  // 1,000
            'loan_balance' => 50000,
        ]);

        $this->assertSame(1000.0, $result['loan_deduction']);
        $this->assertSame(0.0, $result['net_final_pay']);
    }

    public function test_the_breakdown_names_what_is_still_outstanding(): void
    {
        $result = $this->compute([
            'basic_earned_this_year' => 12000,  // 1,000
            'loan_balance' => 3500,
        ]);

        $loanLine = collect($result['lines'])->firstWhere('label', 'Less: outstanding loans');

        $this->assertNotNull($loanLine);
        $this->assertSame(-1000.0, $loanLine['amount']);
        // 3,500 − 1,000 still owed after the settlement is exhausted.
        $this->assertStringContainsString('2,500.00', $loanLine['note']);
    }

    /** Other deductions come off before the loan can claim what is left. */
    public function test_other_deductions_take_priority_over_the_loan(): void
    {
        $result = $this->compute([
            'basic_earned_this_year' => 120000,  // 10,000
            'loan_balance' => 20000,
            'other_deductions' => 2000,
        ]);

        $this->assertSame(8000.0, $result['loan_deduction']);
        $this->assertSame(2000.0, $result['other_deductions']);
        $this->assertSame(0.0, $result['net_final_pay']);
    }

    public function test_nothing_owed_produces_a_zero_settlement_not_an_error(): void
    {
        $result = $this->compute([]);

        $this->assertSame(0.0, $result['gross']);
        $this->assertSame(0.0, $result['net_final_pay']);
        $this->assertNotEmpty($result['lines']);
    }

    /**
     * Separation pay is deliberately absent: it is owed only for authorised
     * causes at rates that depend on which cause applies, so it is a decision
     * HR records, not arithmetic the system performs silently.
     */
    public function test_it_never_invents_separation_pay(): void
    {
        $labels = collect($this->compute(['basic_earned_this_year' => 120000])['lines'])
            ->pluck('label')
            ->map(fn (string $label) => strtolower($label));

        $this->assertFalse($labels->contains(fn (string $label) => str_contains($label, 'separation')));
    }

    private function compute(array $input): array
    {
        return (new FinalPayCalculator)->compute([
            'monthly_salary' => self::SALARY,
            'days_unpaid' => 0,
            'basic_earned_this_year' => 0,
            'convertible_leave_days' => 0,
            'loan_balance' => 0,
            ...$input,
        ]);
    }
}
