<?php

namespace App\Services;

use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * 13th-month pay under Presidential Decree 851.
 *
 * The rule is one line — total **basic salary earned** in the calendar year,
 * divided by twelve — and the whole difficulty is in "earned".
 *
 * A payslip's `basic_pay` is the *nominal* period salary: PayrollCalculator
 * writes `monthly ÷ periods` and then takes lateness, undertime, absences,
 * and unpaid leave off separately as deductions. So `basic_pay` alone is what
 * the employee was *entitled* to, not what they earned — using it would pay a
 * full 13th month to someone who was absent for a month unpaid. This subtracts
 * those four deductions to get back to the earned figure.
 *
 * Deliberately excluded, per PD 851 and the DOLE guidelines: allowances,
 * overtime, night differential, holiday premium, and the statutory
 * contributions — 13th month is computed on basic salary, not on gross pay,
 * and not on take-home.
 *
 * Pro-rating needs no special case. An employee hired in September simply has
 * fewer payslips in the year, so their earned total is smaller and the ÷12
 * lands where it should.
 *
 * Database-free and unit tested, the same shape as PayrollCalculator: it takes
 * payslips and returns rows.
 */
class ThirteenthMonthCalculator
{
    /** PD 851 divides by twelve regardless of how many months were worked. */
    private const MONTHS = 12;

    /**
     * @param  Collection<int, Payslip>  $payslips  every payslip in the year
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, float|int>
     * }
     */
    public function build(Collection $payslips): array
    {
        $rows = $payslips
            ->groupBy('employee_id')
            ->map(fn (Collection $slips) => $this->row($slips))
            ->filter()
            ->sortBy('employee_name')
            ->values();

        return [
            'rows' => $rows->all(),
            'totals' => [
                'employees' => $rows->count(),
                'basic_earned' => round((float) $rows->sum('basic_earned'), 2),
                'amount' => round((float) $rows->sum('amount'), 2),
            ],
        ];
    }

    /**
     * @param  Collection<int, Payslip>  $slips  one employee's payslips
     * @return array<string, mixed>|null
     */
    private function row(Collection $slips): ?array
    {
        $employee = $slips->first()->employee;

        if ($employee === null) {
            return null;
        }

        $earned = round($slips->sum(fn (Payslip $slip) => $this->earned($slip)), 2);

        // A negative total would mean deductions exceeded basic pay for the
        // year — nothing was earned, and 13th month cannot go below zero.
        $earned = max($earned, 0.0);

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'department' => $employee->department?->name,
            'periods_paid' => $slips->count(),
            'basic_earned' => $earned,
            'amount' => round($earned / self::MONTHS, 2),
        ];
    }

    /** One payslip's basic salary actually earned, after time not worked. */
    private function earned(Payslip $slip): float
    {
        return (float) $slip->basic_pay
            - (float) $slip->late_deduction
            - (float) $slip->undertime_deduction
            - (float) $slip->absence_deduction
            - (float) $slip->unpaid_leave_deduction;
    }
}
