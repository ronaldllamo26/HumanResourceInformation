<?php

namespace App\Services;

/**
 * Philippine statutory contributions and withholding tax.
 *
 * Database-free and driven entirely by config/payroll.php, so a rate change is
 * a config edit rather than a code change. Every bracket here is unit tested
 * against the published tables.
 */
class StatutoryContributions
{
    /** Pay frequencies that map to a withholding tax table. */
    private const TAX_TABLES = ['daily', 'weekly', 'semi_monthly', 'monthly'];

    /**
     * SSS employee and employer shares for a monthly compensation.
     *
     * @return array{employee: float, employer: float, msc: float}
     */
    public function sss(float $monthlyCompensation): array
    {
        $config = config('payroll.sss');
        $msc = $this->monthlySalaryCredit($monthlyCompensation, $config);

        return [
            'employee' => round($msc * $config['employee_rate'], 2),
            'employer' => round($msc * $config['employer_rate'], 2),
            'msc' => $msc,
        ];
    }

    /**
     * PhilHealth premium, split evenly between employee and employer.
     *
     * @return array{employee: float, employer: float}
     */
    public function philhealth(float $monthlyBasicSalary): array
    {
        $config = config('payroll.philhealth');

        $base = min(
            max($monthlyBasicSalary, $config['income_floor']),
            $config['income_ceiling'],
        );

        $premium = $base * $config['premium_rate'];
        $employee = round($premium * $config['employee_share'], 2);

        return [
            'employee' => $employee,
            // Whatever the premium leaves over, so the halves always reconcile.
            'employer' => round($premium - $employee, 2),
        ];
    }

    /**
     * Pag-IBIG. The fund salary is capped, so the employee share tops out at
     * 2% of the cap regardless of actual pay.
     *
     * @return array{employee: float, employer: float}
     */
    public function pagibig(float $monthlyCompensation): array
    {
        $config = config('payroll.pagibig');

        $fundSalary = min($monthlyCompensation, $config['fund_salary_cap']);

        $employeeRate = $monthlyCompensation <= $config['lower_bracket_ceiling']
            ? $config['employee_rate_lower']
            : $config['employee_rate_upper'];

        return [
            'employee' => round($fundSalary * $employeeRate, 2),
            'employer' => round($fundSalary * $config['employer_rate'], 2),
        ];
    }

    /**
     * Withholding tax on compensation.
     *
     * `$taxableIncome` is gross pay for the period less the employee's
     * statutory contributions — the caller subtracts those, because only it
     * knows which of them were withheld this run.
     */
    public function withholdingTax(float $taxableIncome, string $frequency = 'semi_monthly'): float
    {
        if ($taxableIncome <= 0) {
            return 0.0;
        }

        $table = config('payroll.withholding_tax.'.$this->normaliseFrequency($frequency));

        $bracket = [0, 0, 0.0];

        foreach ($table as $candidate) {
            if ($taxableIncome > $candidate[0]) {
                $bracket = $candidate;

                continue;
            }

            break;
        }

        [$floor, $baseTax, $rate] = $bracket;

        return round($baseTax + (($taxableIncome - $floor) * $rate), 2);
    }

    /**
     * All employee-side statutory deductions for a period, with the employer
     * share reported alongside for remittance.
     *
     * Contributions are assessed monthly, so a semi-monthly run withholds half.
     *
     * @return array<string, float>
     */
    public function forPeriod(float $monthlyBasicSalary, string $frequency = 'semi_monthly'): array
    {
        $divisor = $this->periodsPerMonth($frequency);

        $sss = $this->sss($monthlyBasicSalary);
        $philhealth = $this->philhealth($monthlyBasicSalary);
        $pagibig = $this->pagibig($monthlyBasicSalary);

        return [
            'sss_employee' => round($sss['employee'] / $divisor, 2),
            'sss_employer' => round($sss['employer'] / $divisor, 2),
            'philhealth_employee' => round($philhealth['employee'] / $divisor, 2),
            'philhealth_employer' => round($philhealth['employer'] / $divisor, 2),
            'pagibig_employee' => round($pagibig['employee'] / $divisor, 2),
            'pagibig_employer' => round($pagibig['employer'] / $divisor, 2),
        ];
    }

    /** How many pay periods a month contains, for splitting monthly figures. */
    public function periodsPerMonth(string $frequency): float
    {
        return match ($frequency) {
            'monthly' => 1,
            'semi_monthly' => 2,
            'weekly' => 4.33,
            'daily' => 22,
            default => 2,
        };
    }

    /** Rounds compensation down to its MSC bracket, then clamps to the range. */
    private function monthlySalaryCredit(float $compensation, array $config): float
    {
        if ($compensation <= $config['msc_floor']) {
            return (float) $config['msc_floor'];
        }

        if ($compensation >= $config['msc_ceiling']) {
            return (float) $config['msc_ceiling'];
        }

        $step = $config['msc_step'];

        return (float) (floor($compensation / $step) * $step);
    }

    private function normaliseFrequency(string $frequency): string
    {
        return in_array($frequency, self::TAX_TABLES, true) ? $frequency : 'semi_monthly';
    }
}
