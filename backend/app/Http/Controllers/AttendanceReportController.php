<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Services\DataAccessLogger;
use App\Services\TimekeepingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 2 — the attendance summary, streamed as CSV for payroll hand-off.
 *
 * There is no screen here any more. This used to render a Reports page beside
 * Records showing the same per-employee figures for the same range, with no
 * way to reach the days behind them; Records absorbed it, and what was left
 * worth keeping was the export and the access row it writes.
 */
class AttendanceReportController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function export(Request $request, DataAccessLogger $access): StreamedResponse
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $filters = $this->range($request);
        $query = $this->timekeeping->scopedQuery($request->user())->filter($filters);
        $rows = $this->timekeeping->employeeSummaries($query);

        // The range is what makes this row answer anything: "someone exported
        // attendance" is noise, "someone exported the whole of August" is not.
        $access->exported('attendance-report', AttendanceLog::class, [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'employees' => $rows->count(),
        ]);

        $filename = "attendance-{$filters['from']}-to-{$filters['to']}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Employee Number', 'Employee', 'Department', 'Days Present', 'Days Absent',
                'Late Count', 'Late Minutes', 'Undertime Minutes', 'Overtime Hours',
                'Night Diff Hours', 'Total Hours',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['employee_number'],
                    $row['full_name'],
                    $row['department'] ?? '',
                    $row['days_present'],
                    $row['days_absent'],
                    $row['late_count'],
                    $row['late_minutes'],
                    $row['undertime_minutes'],
                    $row['overtime_hours'],
                    $row['night_diff_hours'],
                    $row['total_hours'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The two dates the CSV covers.
     *
     * Read straight off the request rather than resolved from a period name,
     * because the Records screen already holds a concrete range and hands it
     * over — a second notion of what "monthly" means would be the export
     * quietly covering a different fortnight from the screen it was clicked
     * on. Defaults match that screen's own default.
     *
     * @return array<string, string>
     */
    private function range(Request $request): array
    {
        return [
            'from' => Carbon::parse(
                $request->query('from', Carbon::today()->startOfMonth()->toDateString()),
            )->toDateString(),
            'to' => Carbon::parse(
                $request->query('to', Carbon::today()->endOfMonth()->toDateString()),
            )->toDateString(),
        ];
    }
}
