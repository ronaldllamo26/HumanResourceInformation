<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\DataAccessLogger;
use App\Services\ThirteenthMonthCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 4 — 13th-month pay.
 *
 * Reads finalised payslips the same way Compliance does: only *approved* and
 * *paid* runs count, because a draft is still being corrected and 13th month
 * computed from figures that later move would have to be corrected too.
 */
class ThirteenthMonthController extends Controller
{
    public function __construct(private readonly ThirteenthMonthCalculator $calculator) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $year = (int) $request->query('year', Carbon::now()->year);

        $built = $this->calculator->build($this->payslips($year));

        return Inertia::render('HR/Payroll/ThirteenthMonth', [
            'rows' => $built['rows'],
            'totals' => $built['totals'],
            'filters' => ['year' => $year],
            'years' => $this->years($year),
            // PD 851 puts the deadline at 24 December. Saying so on the screen
            // is the difference between a report and a reminder.
            'deadline' => [
                'date' => Carbon::create($year, 12, 24)->toDateString(),
                'days_remaining' => (int) Carbon::today()->diffInDays(
                    Carbon::create($year, 12, 24), false,
                ),
            ],
        ]);
    }

    public function export(Request $request, DataAccessLogger $access): StreamedResponse
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $year = (int) $request->query('year', Carbon::now()->year);
        $built = $this->calculator->build($this->payslips($year));

        $access->exported('13th-month', Payslip::class, [
            'year' => $year,
            'employees' => count($built['rows'] ?? []),
        ]);

        return response()->streamDownload(function () use ($built) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee No.', 'Employee Name', 'Department',
                'Periods Paid', 'Basic Salary Earned', '13th Month Pay',
            ]);

            foreach ($built['rows'] as $row) {
                fputcsv($handle, [
                    $row['employee_number'],
                    $row['employee_name'],
                    $row['department'] ?? '',
                    $row['periods_paid'],
                    $row['basic_earned'],
                    $row['amount'],
                ]);
            }

            // A control total, so the filer can check the transmittal without
            // adding the column up by hand.
            fputcsv($handle, []);
            fputcsv($handle, [
                'TOTAL', '', '',
                $built['totals']['employees'],
                $built['totals']['basic_earned'],
                $built['totals']['amount'],
            ]);

            fclose($handle);
        }, "13th-month-{$year}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return Collection<int, Payslip> */
    private function payslips(int $year)
    {
        return Payslip::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id', 'employee.department:id,name'])
            ->whereHas('run', fn ($query) => $query
                ->reportable()
                ->whereHas('period', fn ($inner) => $inner->whereYear('end_date', $year)),
            )
            ->get();
    }

    /**
     * Years that actually have finalised payroll behind them, plus the one
     * being viewed — so the picker never offers an empty year.
     *
     * @return array<int, int>
     */
    private function years(int $viewing): array
    {
        // collect() first: mapping an Eloquent collection to scalars leaves an
        // Eloquent collection, and its unique() calls getKey() on each item.
        $years = collect(
            PayrollRun::reportable()
                ->with('period:id,end_date')
                ->get()
                ->all(),
        )
            ->map(fn (PayrollRun $run) => (int) $run->period?->end_date?->year)
            ->filter()
            ->push($viewing)
            ->unique()
            ->sortDesc()
            ->values();

        return $years->all();
    }
}
