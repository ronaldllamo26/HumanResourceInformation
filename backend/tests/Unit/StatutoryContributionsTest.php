<?php

namespace Tests\Unit;

use App\Services\StatutoryContributions;
use Tests\TestCase;

/**
 * Asserted against the published Philippine tables. If a circular changes the
 * rates, update config/payroll.php and these expectations together.
 */
class StatutoryContributionsTest extends TestCase
{
    private StatutoryContributions $statutory;

    // --- SSS --------------------------------------------------------------

    public function test_sss_is_five_and_ten_percent_of_the_salary_credit(): void
    {
        $result = $this->statutory->sss(20000);

        $this->assertSame(20000.0, $result['msc']);
        $this->assertSame(1000.0, $result['employee']);  // 5%
        $this->assertSame(2000.0, $result['employer']);  // 10%
    }

    public function test_sss_floors_low_earners_at_the_minimum_salary_credit(): void
    {
        $result = $this->statutory->sss(3000);

        $this->assertSame(5000.0, $result['msc']);
        $this->assertSame(250.0, $result['employee']);
    }

    public function test_sss_caps_high_earners_at_the_maximum_salary_credit(): void
    {
        $result = $this->statutory->sss(120000);

        $this->assertSame(35000.0, $result['msc']);
        $this->assertSame(1750.0, $result['employee']);
    }

    public function test_sss_rounds_compensation_down_to_its_bracket(): void
    {
        // 20,300 and 20,700 sit inside the 20,000 and 20,500 brackets.
        $this->assertSame(20000.0, $this->statutory->sss(20300)['msc']);
        $this->assertSame(20500.0, $this->statutory->sss(20700)['msc']);
    }

    // --- PhilHealth -------------------------------------------------------

    public function test_philhealth_splits_the_five_percent_premium_evenly(): void
    {
        $result = $this->statutory->philhealth(20000);

        $this->assertSame(500.0, $result['employee']);
        $this->assertSame(500.0, $result['employer']);
    }

    public function test_philhealth_applies_the_income_floor_and_ceiling(): void
    {
        // Below the 10,000 floor, the premium is still assessed on 10,000.
        $this->assertSame(250.0, $this->statutory->philhealth(6000)['employee']);

        // Above the 100,000 ceiling it stops growing.
        $this->assertSame(2500.0, $this->statutory->philhealth(180000)['employee']);
    }

    // --- Pag-IBIG ---------------------------------------------------------

    public function test_pagibig_caps_the_employee_share_at_two_hundred(): void
    {
        $result = $this->statutory->pagibig(45000);

        $this->assertSame(200.0, $result['employee']);
        $this->assertSame(200.0, $result['employer']);
    }

    public function test_pagibig_uses_one_percent_for_the_lowest_bracket(): void
    {
        $this->assertSame(15.0, $this->statutory->pagibig(1500)['employee']);
        $this->assertSame(14.0, $this->statutory->pagibig(1400)['employee']);

        // Just over the bracket the rate doubles.
        $this->assertSame(30.02, $this->statutory->pagibig(1501)['employee']);
    }

    // --- Withholding tax --------------------------------------------------

    public function test_income_at_or_below_the_exemption_is_not_taxed(): void
    {
        $this->assertSame(0.0, $this->statutory->withholdingTax(20833, 'monthly'));
        $this->assertSame(0.0, $this->statutory->withholdingTax(0, 'monthly'));
        $this->assertSame(0.0, $this->statutory->withholdingTax(-500, 'monthly'));
    }

    public function test_the_monthly_tax_brackets_match_the_published_table(): void
    {
        // 15% of the excess over 20,833.
        $this->assertSame(1375.05, $this->statutory->withholdingTax(30000, 'monthly'));

        // 1,875 + 20% of the excess over 33,333.
        $this->assertSame(5208.40, $this->statutory->withholdingTax(50000, 'monthly'));

        // 8,541.80 + 25% of the excess over 66,667.
        $this->assertSame(16875.05, $this->statutory->withholdingTax(100000, 'monthly'));

        // 33,541.80 + 30% of the excess over 166,667 (33,333 x 0.30 = 9,999.90).
        $this->assertSame(43541.70, $this->statutory->withholdingTax(200000, 'monthly'));
    }

    public function test_the_semi_monthly_table_is_used_for_semi_monthly_pay(): void
    {
        // 15% of the excess over 10,417.
        $this->assertSame(687.45, $this->statutory->withholdingTax(15000, 'semi_monthly'));

        // A semi-monthly run must not be taxed on the monthly table.
        $this->assertNotSame(
            $this->statutory->withholdingTax(15000, 'monthly'),
            $this->statutory->withholdingTax(15000, 'semi_monthly'),
        );
    }

    public function test_an_unknown_frequency_falls_back_to_semi_monthly(): void
    {
        $this->assertSame(
            $this->statutory->withholdingTax(15000, 'semi_monthly'),
            $this->statutory->withholdingTax(15000, 'fortnightly'),
        );
    }

    // --- Period splitting -------------------------------------------------

    public function test_a_semi_monthly_period_withholds_half_the_monthly_contribution(): void
    {
        $monthly = $this->statutory->forPeriod(20000, 'monthly');
        $semi = $this->statutory->forPeriod(20000, 'semi_monthly');

        $this->assertSame(1000.0, $monthly['sss_employee']);
        $this->assertSame(500.0, $semi['sss_employee']);
        $this->assertSame(250.0, $semi['philhealth_employee']);
        $this->assertSame(100.0, $semi['pagibig_employee']);
    }

    public function test_employer_shares_are_reported_for_remittance(): void
    {
        $result = $this->statutory->forPeriod(20000, 'monthly');

        $this->assertSame(2000.0, $result['sss_employer']);
        $this->assertSame(500.0, $result['philhealth_employer']);
        $this->assertSame(200.0, $result['pagibig_employer']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->statutory = new StatutoryContributions;
    }
}
