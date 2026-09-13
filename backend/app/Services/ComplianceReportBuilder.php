<?php

namespace App\Services;

use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Module 4 — statutory remittance and BIR reporting.
 *
 * Every figure here is read back from stored payslips, never recomputed. A run
 * that has been approved is what the employee was actually paid; recalculating
 * it at report time would let a later config change (a new SSS circular) quietly
 * rewrite history and disagree with the payslips already issued.
 *
 * Database-free by design, like PayrollCalculator — it takes payslips and
 * returns rows, so the arithmetic is unit tested without a payroll run.
 */
class ComplianceReportBuilder
{
    public const REPORT_SSS = 'sss';

    public const REPORT_PHILHEALTH = 'philhealth';

    public const REPORT_PAGIBIG = 'pagibig';

    public const REPORT_BIR = 'bir';

    /** Which employee ID each report cannot be filed without. */
    private const REQUIRED_ID = [
        self::REPORT_SSS => 'sss_number',
        self::REPORT_PHILHEALTH => 'philhealth_number',
        self::REPORT_PAGIBIG => 'pagibig_number',
        self::REPORT_BIR => 'tin',
    ];

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, float>,
     *     missing_ids: array<int, string>
     * }
     */
    public function build(string $report, Collection $payslips): array
    {
        $rows = $payslips
            ->map(fn (Payslip $payslip) => $this->row($report, $payslip))
            ->sortBy('employee_name')
            ->values();

        return [
            'rows' => $rows->all(),
            'totals' => $this->totals($rows),
            // An employee with no SSS number cannot appear on an R-3, so the
            // report is not filable until HR fills it in — worth surfacing
            // before someone submits a short remittance.
            'missing_ids' => $rows->where('has_id', false)->pluck('employee_name')->values()->all(),
        ];
    }

    /** Column headings for the on-screen table and the CSV, in order. */
    public function columns(string $report): array
    {
        return match ($report) {
            self::REPORT_BIR => [
                'employee_number' => 'Employee No.',
                'employee_name' => 'Employee Name',
                'identifier' => 'TIN',
                'gross' => 'Gross Compensation',
                'contributions' => 'Statutory Contributions',
                'taxable' => 'Taxable Income',
                'tax' => 'Tax Withheld',
            ],
            default => [
                'employee_number' => 'Employee No.',
                'employee_name' => 'Employee Name',
                'identifier' => $this->identifierLabel($report),
                'basis' => 'Monthly Basis',
                'employee_share' => 'EE Share',
                'employer_share' => 'ER Share',
                'total' => 'Total',
            ],
        };
    }

    public function label(string $report): string
    {
        return match ($report) {
            self::REPORT_SSS => 'SSS Contributions (R-3)',
            self::REPORT_PHILHEALTH => 'PhilHealth Contributions (RF-1)',
            self::REPORT_PAGIBIG => 'Pag-IBIG Contributions (MCRF)',
            self::REPORT_BIR => 'BIR Withholding Tax (Alphalist)',
            default => $report,
        };
    }

    private function identifierLabel(string $report): string
    {
        return match ($report) {
            self::REPORT_SSS => 'SSS No.',
            self::REPORT_PHILHEALTH => 'PhilHealth No.',
            self::REPORT_PAGIBIG => 'Pag-IBIG MID No.',
            default => 'TIN',
        };
    }

    /** @return array<string, mixed> */
    private function row(string $report, Payslip $payslip): array
    {
        $employee = $payslip->employee;
        $field = self::REQUIRED_ID[$report];
        $identifier = $employee?->{$field};

        $base = [
            'employee_id' => $payslip->employee_id,
            'employee_number' => $employee?->employee_number ?? '—',
            'employee_name' => $employee?->full_name ?? '—',
            'identifier' => $identifier ?: '— not on file —',
            'has_id' => filled($identifier),
        ];

        if ($report === self::REPORT_BIR) {
            $contributions = round(
                (float) $payslip->sss_employee
                + (float) $payslip->philhealth_employee
                + (float) $payslip->pagibig_employee,
                2,
            );

            return [
                ...$base,
                'gross' => (float) $payslip->gross_pay,
                'contributions' => $contributions,
                // What the withholding was actually assessed on, derived the
                // same way PayrollCalculator derived it.
                'taxable' => round((float) $payslip->gross_pay - $contributions, 2),
                'tax' => (float) $payslip->withholding_tax,
            ];
        }

        $employeeShare = (float) $payslip->{"{$report}_employee"};
        $employerShare = (float) $payslip->{"{$report}_employer"};

        return [
            ...$base,
            'basis' => (float) ($employee?->basic_salary ?? 0),
            'employee_share' => $employeeShare,
            'employer_share' => $employerShare,
            'total' => round($employeeShare + $employerShare, 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function totals(Collection $rows): array
    {
        $sum = fn (string $key) => round((float) $rows->sum($key), 2);

        if ($rows->isNotEmpty() && array_key_exists('tax', $rows->first())) {
            return [
                'gross' => $sum('gross'),
                'contributions' => $sum('contributions'),
                'taxable' => $sum('taxable'),
                'tax' => $sum('tax'),
            ];
        }

        return [
            'employee_share' => $sum('employee_share'),
            'employer_share' => $sum('employer_share'),
            'total' => $sum('total'),
        ];
    }
}
