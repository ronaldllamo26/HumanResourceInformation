<?php

namespace App\Services;

use App\Models\PayslipLine;

/**
 * Turns one employee's period figures into a payslip.
 *
 * Database-free on purpose: PayrollService gathers the inputs, this decides the
 * money. Every rule is unit tested, because a rounding slip here is somebody's
 * pay packet.
 */
class PayrollCalculator
{
    public function __construct(private readonly StatutoryContributions $statutory) {}

    /**
     * @param  array{
     *     monthly_salary: float, pay_frequency?: string,
     *     days_worked?: float, hours_worked?: float,
     *     overtime_hours?: float, night_diff_hours?: float,
     *     late_minutes?: int, undertime_minutes?: int,
     *     absent_days?: float, unpaid_leave_days?: float, holiday_pay?: float,
     *     allowances?: array<int, array{label: string, amount: float, taxable?: bool}>,
     *     loans?: array<int, array{label: string, amount: float}>,
     *     other_deductions?: array<int, array{label: string, amount: float}>,
     * }  $input
     * @return array{payslip: array<string, mixed>, lines: array<int, array<string, mixed>>}
     */
    public function compute(array $input): array
    {
        $monthlySalary = (float) $input['monthly_salary'];
        $frequency = $input['pay_frequency'] ?? 'semi_monthly';

        $rates = $this->rates($monthlySalary);
        $periods = $this->statutory->periodsPerMonth($frequency);

        // --- Earnings ---
        $basicPay = round($monthlySalary / $periods, 2);
        $overtimeHours = (float) ($input['overtime_hours'] ?? 0);
        $nightDiffHours = (float) ($input['night_diff_hours'] ?? 0);

        $overtimePay = round(
            $rates['hourly'] * config('payroll.premiums.overtime') * $overtimeHours,
            2,
        );
        $nightDiffPay = round(
            $rates['hourly'] * config('payroll.premiums.night_differential') * $nightDiffHours,
            2,
        );
        $holidayPay = round((float) ($input['holiday_pay'] ?? 0), 2);

        $allowances = $input['allowances'] ?? [];
        $allowancesTotal = round(array_sum(array_column($allowances, 'amount')), 2);

        $grossPay = round(
            $basicPay + $overtimePay + $nightDiffPay + $holidayPay + $allowancesTotal,
            2,
        );

        // --- Attendance-driven deductions ---
        $lateMinutes = (int) ($input['late_minutes'] ?? 0);
        $undertimeMinutes = (int) ($input['undertime_minutes'] ?? 0);
        $absentDays = (float) ($input['absent_days'] ?? 0);
        $unpaidLeaveDays = (float) ($input['unpaid_leave_days'] ?? 0);

        $lateDeduction = round($rates['per_minute'] * $lateMinutes, 2);
        $undertimeDeduction = round($rates['per_minute'] * $undertimeMinutes, 2);
        $absenceDeduction = round($rates['daily'] * $absentDays, 2);
        $unpaidLeaveDeduction = round($rates['daily'] * $unpaidLeaveDays, 2);

        $attendanceDeductions = round(
            $lateDeduction + $undertimeDeduction + $absenceDeduction + $unpaidLeaveDeduction,
            2,
        );

        // --- Statutory ---
        $contributions = $this->statutory->forPeriod($monthlySalary, $frequency);

        $employeeContributions = round(
            $contributions['sss_employee']
            + $contributions['philhealth_employee']
            + $contributions['pagibig_employee'],
            2,
        );

        // Tax is assessed on what is actually paid: gross, less non-taxable
        // allowances, less time not worked, less the statutory contributions.
        $nonTaxableAllowances = round(
            array_sum(array_map(
                fn (array $allowance) => ($allowance['taxable'] ?? false) ? 0 : $allowance['amount'],
                $allowances,
            )),
            2,
        );

        $taxableIncome = round(
            $grossPay - $nonTaxableAllowances - $attendanceDeductions - $employeeContributions,
            2,
        );

        $withholdingTax = $this->statutory->withholdingTax($taxableIncome, $frequency);

        // --- Other deductions ---
        $loans = $input['loans'] ?? [];
        $loansDeduction = round(array_sum(array_column($loans, 'amount')), 2);

        $others = $input['other_deductions'] ?? [];
        $otherDeductions = round(array_sum(array_column($others, 'amount')), 2);

        $deductionsTotal = round(
            $attendanceDeductions
            + $employeeContributions
            + $withholdingTax
            + $loansDeduction
            + $otherDeductions,
            2,
        );

        $netPay = round($grossPay - $deductionsTotal, 2);

        $payslip = [
            'days_worked' => (float) ($input['days_worked'] ?? 0),
            'hours_worked' => (float) ($input['hours_worked'] ?? 0),
            'overtime_hours' => $overtimeHours,
            'night_diff_hours' => $nightDiffHours,
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'absent_days' => $absentDays,
            'unpaid_leave_days' => $unpaidLeaveDays,

            'basic_pay' => $basicPay,
            'overtime_pay' => $overtimePay,
            'night_diff_pay' => $nightDiffPay,
            'holiday_pay' => $holidayPay,
            'allowances_total' => $allowancesTotal,
            'gross_pay' => $grossPay,

            'sss_employee' => $contributions['sss_employee'],
            'philhealth_employee' => $contributions['philhealth_employee'],
            'pagibig_employee' => $contributions['pagibig_employee'],
            'withholding_tax' => $withholdingTax,

            'sss_employer' => $contributions['sss_employer'],
            'philhealth_employer' => $contributions['philhealth_employer'],
            'pagibig_employer' => $contributions['pagibig_employer'],

            'late_deduction' => $lateDeduction,
            'undertime_deduction' => $undertimeDeduction,
            'absence_deduction' => $absenceDeduction,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'loans_deduction' => $loansDeduction,
            'other_deductions' => $otherDeductions,

            'deductions_total' => $deductionsTotal,
            'net_pay' => $netPay,
        ];

        return [
            'payslip' => $payslip,
            'lines' => $this->lines($payslip, $allowances, $loans, $others),
        ];
    }

    /**
     * Daily, hourly, and per-minute rates derived from the monthly salary.
     *
     * @return array{daily: float, hourly: float, per_minute: float}
     */
    public function rates(float $monthlySalary): array
    {
        $daysPerYear = (int) config('payroll.working_days_per_year');
        $hoursPerDay = (int) config('payroll.hours_per_day');

        $daily = $monthlySalary * 12 / $daysPerYear;
        $hourly = $daily / $hoursPerDay;

        return [
            'daily' => round($daily, 4),
            'hourly' => round($hourly, 4),
            'per_minute' => round($hourly / 60, 4),
        ];
    }

    /**
     * Itemises the payslip. Zero-value lines are dropped so a clean payslip
     * does not read as a wall of noughts.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $payslip, array $allowances, array $loans, array $others): array
    {
        $lines = [];

        $add = function (string $type, string $code, string $label, float $amount) use (&$lines) {
            if (abs($amount) < 0.005) {
                return;
            }

            $lines[] = compact('type', 'code', 'label') + ['amount' => round($amount, 2)];
        };

        $add(PayslipLine::TYPE_EARNING, 'basic', 'Basic Pay', $payslip['basic_pay']);
        $add(PayslipLine::TYPE_EARNING, 'overtime', 'Overtime Pay', $payslip['overtime_pay']);
        $add(PayslipLine::TYPE_EARNING, 'night_diff', 'Night Differential', $payslip['night_diff_pay']);
        $add(PayslipLine::TYPE_EARNING, 'holiday', 'Holiday Pay', $payslip['holiday_pay']);

        foreach ($allowances as $allowance) {
            $add(PayslipLine::TYPE_EARNING, 'allowance', $allowance['label'], (float) $allowance['amount']);
        }

        $add(PayslipLine::TYPE_DEDUCTION, 'late', 'Tardiness', $payslip['late_deduction']);
        $add(PayslipLine::TYPE_DEDUCTION, 'undertime', 'Undertime', $payslip['undertime_deduction']);
        $add(PayslipLine::TYPE_DEDUCTION, 'absence', 'Absences', $payslip['absence_deduction']);
        $add(PayslipLine::TYPE_DEDUCTION, 'unpaid_leave', 'Unpaid Leave', $payslip['unpaid_leave_deduction']);
        $add(PayslipLine::TYPE_DEDUCTION, 'sss', 'SSS Contribution', $payslip['sss_employee']);
        $add(PayslipLine::TYPE_DEDUCTION, 'philhealth', 'PhilHealth Contribution', $payslip['philhealth_employee']);
        $add(PayslipLine::TYPE_DEDUCTION, 'pagibig', 'Pag-IBIG Contribution', $payslip['pagibig_employee']);
        $add(PayslipLine::TYPE_DEDUCTION, 'tax', 'Withholding Tax', $payslip['withholding_tax']);

        foreach ($loans as $loan) {
            $add(PayslipLine::TYPE_DEDUCTION, 'loan', $loan['label'], (float) $loan['amount']);
        }

        foreach ($others as $other) {
            $add(PayslipLine::TYPE_DEDUCTION, 'other', $other['label'], (float) $other['amount']);
        }

        return $lines;
    }
}
