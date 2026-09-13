<?php

namespace App\Services;

/**
 * What a departing employee is owed.
 *
 * Database-free and unit tested, like the other calculators: it takes numbers
 * and returns numbers, so every rule can be checked without a payroll run.
 *
 * The four components, per DOLE Labor Advisory 06-20:
 *
 *  - unpaid salary for days worked since the last payroll run closed
 *  - pro-rated 13th month for the year up to the last day
 *  - the cash value of unused, convertible leave credits
 *  - less any outstanding loan balance
 *
 * Deliberately **not** here: separation pay. It is owed only for authorised
 * causes (redundancy, retrenchment, closure, disease) at rates that depend on
 * which cause applies, and getting that wrong in either direction is a labour
 * case. It belongs in a decision HR records, not in arithmetic the system
 * performs silently.
 */
class FinalPayCalculator
{
    /**
     * @param  array{
     *     monthly_salary: float,
     *     days_unpaid: float,
     *     basic_earned_this_year: float,
     *     convertible_leave_days: float,
     *     loan_balance: float,
     *     other_deductions?: float
     * }  $input
     * @return array{
     *     unpaid_salary: float,
     *     thirteenth_month: float,
     *     leave_conversion: float,
     *     loan_deduction: float,
     *     other_deductions: float,
     *     gross: float,
     *     net_final_pay: float,
     *     lines: array<int, array{label: string, amount: float, note: string}>
     * }
     */
    public function compute(array $input): array
    {
        $monthly = (float) ($input['monthly_salary'] ?? 0);
        $daily = $this->dailyRate($monthly);

        $unpaidSalary = round($daily * (float) ($input['days_unpaid'] ?? 0), 2);

        // Same rule as the 13th-month screen: basic earned ÷ 12. Pro-rating
        // falls out of the earned figure being smaller, not a separate factor.
        $thirteenth = round(((float) ($input['basic_earned_this_year'] ?? 0)) / 12, 2);

        $leaveDays = (float) ($input['convertible_leave_days'] ?? 0);
        $leaveConversion = round($daily * $leaveDays, 2);

        $gross = round($unpaidSalary + $thirteenth + $leaveConversion, 2);

        // A loan cannot take more than the settlement holds. Anything left is
        // a debt to collect, not a negative cheque to hand someone.
        $loanBalance = (float) ($input['loan_balance'] ?? 0);
        $other = round((float) ($input['other_deductions'] ?? 0), 2);
        $loanDeduction = round(min($loanBalance, max($gross - $other, 0)), 2);

        $net = round($gross - $loanDeduction - $other, 2);

        return [
            'unpaid_salary' => $unpaidSalary,
            'thirteenth_month' => $thirteenth,
            'leave_conversion' => $leaveConversion,
            'loan_deduction' => $loanDeduction,
            'other_deductions' => $other,
            'gross' => $gross,
            'net_final_pay' => max($net, 0.0),
            'lines' => $this->lines(
                $daily, $input, $unpaidSalary, $thirteenth,
                $leaveDays, $leaveConversion, $loanDeduction, $loanBalance, $other,
            ),
        ];
    }

    /**
     * The working, kept beside the figure it explains — a settlement someone
     * cannot check is a settlement they will dispute.
     *
     * @return array<int, array{label: string, amount: float, note: string}>
     */
    private function lines(
        float $daily,
        array $input,
        float $unpaidSalary,
        float $thirteenth,
        float $leaveDays,
        float $leaveConversion,
        float $loanDeduction,
        float $loanBalance,
        float $other,
    ): array {
        $days = (float) ($input['days_unpaid'] ?? 0);
        $rate = number_format($daily, 2);

        $lines = [
            [
                'label' => 'Unpaid salary',
                'amount' => $unpaidSalary,
                'note' => "{$days} day(s) at ₱{$rate}/day",
            ],
            [
                'label' => 'Pro-rated 13th-month pay',
                'amount' => $thirteenth,
                'note' => '₱'.number_format((float) ($input['basic_earned_this_year'] ?? 0), 2).' basic earned ÷ 12',
            ],
            [
                'label' => 'Leave conversion',
                'amount' => $leaveConversion,
                'note' => "{$leaveDays} convertible day(s) at ₱{$rate}/day",
            ],
        ];

        if ($loanDeduction > 0 || $loanBalance > 0) {
            $remaining = round($loanBalance - $loanDeduction, 2);

            $lines[] = [
                'label' => 'Less: outstanding loans',
                'amount' => -$loanDeduction,
                'note' => $remaining > 0
                    ? '₱'.number_format($remaining, 2).' remains outstanding after this settlement'
                    : 'Loan fully settled',
            ];
        }

        if ($other > 0) {
            $lines[] = [
                'label' => 'Less: other deductions',
                'amount' => -$other,
                'note' => 'Entered by HR',
            ];
        }

        return $lines;
    }

    /** Same derivation as PayrollCalculator, so the two cannot disagree. */
    private function dailyRate(float $monthly): float
    {
        $factor = (int) config('payroll.working_days_per_year', 261);

        return $factor > 0 ? round($monthly * 12 / $factor, 2) : 0.0;
    }
}
