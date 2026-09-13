<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\ComplianceReportBuilder;
use App\Services\DataAccessLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 4 — statutory remittance and BIR reporting.
 *
 * Reads back what was actually paid. Only approved and paid runs are
 * reportable: a draft is still being corrected, and remitting against figures
 * that later change means filing a correction with the agency.
 */
class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceReportBuilder $builder) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::with('period:id,name,start_date,end_date')
            ->reportable()
            ->latest('id')
            ->get();

        $report = $this->resolveReport($request);
        $run = $this->resolveRun($request, $runs->pluck('id'));

        $built = $run
            ? $this->builder->build($report, $this->payslips($run))
            : ['rows' => [], 'totals' => [], 'missing_ids' => []];

        return Inertia::render('HR/Payroll/Compliance', [
            'report' => $report,
            'reportLabel' => $this->builder->label($report),
            'columns' => $this->builder->columns($report),
            'rows' => $built['rows'],
            'totals' => $built['totals'],
            'missingIds' => $built['missing_ids'],
            'reports' => $this->reportOptions(),
            'runs' => $runs->map(fn (PayrollRun $item) => [
                'value' => $item->id,
                'label' => "{$item->run_number} — {$item->period->name}",
            ])->values(),
            'selectedRun' => $run?->id,
            'runSummary' => $run ? [
                'run_number' => $run->run_number,
                'period' => $run->period->name,
                'start_date' => $run->period->start_date->toDateString(),
                'end_date' => $run->period->end_date->toDateString(),
                'employee_count' => $run->employee_count,
            ] : null,
        ]);
    }

    /** The same rows the screen shows, as a file an agency portal accepts. */
    public function export(Request $request, DataAccessLogger $access): StreamedResponse
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $report = $this->resolveReport($request);

        $run = PayrollRun::with('period')
            ->reportable()
            ->findOrFail($request->query('run'));

        $built = $this->builder->build($report, $this->payslips($run));
        $columns = $this->builder->columns($report);

        /*
         * The heaviest extract in the system: the alphalist carries every
         * employee's TIN, the agency forms their SSS, PhilHealth, and Pag-IBIG
         * numbers, and all of them carry pay. Without this row a full copy of
         * the workforce's government numbers leaves no trace at all.
         */
        $access->exported("compliance:{$report}", Payslip::class, [
            'run' => $run->run_number,
            'period' => $run->period?->name,
            'employees' => count($built['rows']),
        ]);

        $filename = sprintf('%s-%s.csv', $report, $run->run_number);

        return response()->streamDownload(function () use ($built, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, array_values($columns));

            foreach ($built['rows'] as $row) {
                fputcsv($handle, array_map(
                    fn (string $key) => $row[$key] ?? '',
                    array_keys($columns),
                ));
            }

            // The agency form wants a control total; without it the filer has
            // to add the column up by hand to check the transmittal.
            fputcsv($handle, []);
            fputcsv($handle, array_merge(
                ['TOTAL', '', ''],
                array_map(
                    fn (string $key) => $built['totals'][$key] ?? '',
                    array_slice(array_keys($columns), 3),
                ),
            ));

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return Collection<int, Payslip> */
    private function payslips(PayrollRun $run)
    {
        return Payslip::with('employee:id,employee_number,first_name,middle_name,last_name,suffix,basic_salary,sss_number,philhealth_number,pagibig_number,tin')
            ->where('payroll_run_id', $run->id)
            ->get();
    }

    private function resolveReport(Request $request): string
    {
        $report = (string) $request->query('report', ComplianceReportBuilder::REPORT_SSS);

        return in_array($report, array_column($this->reportOptions(), 'value'), true)
            ? $report
            : ComplianceReportBuilder::REPORT_SSS;
    }

    /** Defaults to the latest reportable run, so the page is never empty. */
    private function resolveRun(Request $request, $reportableIds): ?PayrollRun
    {
        $requested = $request->query('run');

        $id = $reportableIds->contains($requested) ? $requested : $reportableIds->first();

        return $id ? PayrollRun::with('period')->find($id) : null;
    }

    /** @return array<int, array{value: string, label: string}> */
    private function reportOptions(): array
    {
        return array_map(fn (string $value) => [
            'value' => $value,
            'label' => $this->builder->label($value),
        ], [
            ComplianceReportBuilder::REPORT_SSS,
            ComplianceReportBuilder::REPORT_PHILHEALTH,
            ComplianceReportBuilder::REPORT_PAGIBIG,
            ComplianceReportBuilder::REPORT_BIR,
        ]);
    }
}
