<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Services\DataAccessLogger;
use App\Services\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports — one screen, five reports, three ways out (screen, PDF, CSV).
 *
 * The screen, the PDF and the CSV all render one `ReportBuilder::build()`
 * result, so the download cannot disagree with the preview somebody read
 * before pressing it.
 *
 * **HR and admin only, and every download is logged.** A report carries many
 * people's records in one file that then lives in a downloads folder, which is
 * exactly what `DataAccessLogger::exported()` exists to keep a trace of — the
 * same treatment the compliance exports get.
 */
class ReportController extends Controller
{
    /** Past this, the screen shows a slice and says so; the download carries everything. */
    private const PREVIEW_ROWS = 100;

    public function __construct(
        private readonly ReportBuilder $reports,
        private readonly DataAccessLogger $logger,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewReports', Setting::class);

        [$report, $filters] = $this->requested($request);
        $built = $this->reports->build($report, $filters);

        return Inertia::render('HR/Reports/Index', [
            'reports' => $this->reports->definitions(),
            'report' => $report,
            'filters' => $filters,
            'result' => [
                ...$built,
                'rows' => array_slice($built['rows'], 0, self::PREVIEW_ROWS),
                'row_count' => count($built['rows']),
                'truncated' => count($built['rows']) > self::PREVIEW_ROWS,
            ],
            'options' => [
                'departments' => Department::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name]),
                'clients' => Client::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $c) => ['value' => $c->id, 'label' => $c->name]),
                'periods' => PayrollPeriod::orderByDesc('start_date')->limit(24)->get(['id', 'name'])
                    ->map(fn (PayrollPeriod $p) => ['value' => $p->id, 'label' => $p->name]),
                'categories' => collect(Employee::CATEGORIES)
                    ->map(fn (string $value) => ['value' => $value, 'label' => ucfirst($value).' staff']),
                'statuses' => collect(['active', 'on_leave', 'inactive'])
                    ->map(fn (string $value) => ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))]),
                'leaveStatuses' => collect(LeaveRequest::STATUSES)
                    ->map(fn (string $value) => ['value' => $value, 'label' => ucwords(str_replace('_', ' ', $value))]),
            ],
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|HttpResponse
    {
        Gate::authorize('viewReports', Setting::class);

        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        [$report, $filters] = $this->requested($request);
        $built = $this->reports->build($report, $filters);

        // After the gate and before the bytes: a refused request is not an access.
        $this->logger->exported($report, Employee::class, [
            'format' => $format,
            'rows' => count($built['rows']),
            'filters' => array_filter($filters, fn ($value) => $value !== null),
        ]);

        $name = $report.'-'.now()->format('Ymd-His');

        return $format === 'pdf'
            ? $this->pdf($built, $name)
            : $this->csv($built, $name);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function requested(Request $request): array
    {
        $report = in_array($request->input('report'), ReportBuilder::REPORTS, true)
            ? $request->input('report')
            : ReportBuilder::REPORTS[0];

        return [$report, $this->reports->filters($request->all())];
    }

    /** @param array<string, mixed> $built */
    private function pdf(array $built, string $name): HttpResponse
    {
        // Landscape, because a report is wide: the attendance summary is ten
        // columns and portrait would print it as a column of fragments.
        return Pdf::loadView('reports.pdf', [
            'report' => $built,
            'company' => Setting::get('company.name', config('app.name')),
            'printedBy' => request()->user()?->name,
        ])
            ->setPaper('a4', 'landscape')
            ->download($name.'.pdf');
    }

    /** @param array<string, mixed> $built */
    private function csv(array $built, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($built) {
            $handle = fopen('php://output', 'w');

            // The heading travels with the file: a CSV opened three weeks later
            // has to say what it is a report of and which filters made it.
            fputcsv($handle, [$built['title']]);
            fputcsv($handle, [$built['subtitle']]);
            fputcsv($handle, []);

            fputcsv($handle, array_column($built['columns'], 'label'));

            foreach ($built['rows'] as $row) {
                fputcsv($handle, array_map(
                    fn (array $column) => $row[$column['key']] ?? null,
                    $built['columns'],
                ));
            }

            // A control total, the same reason the compliance exports carry one:
            // it is what somebody reconciles the file against.
            if ($built['totals']) {
                fputcsv($handle, []);
                fputcsv($handle, array_map(
                    fn (array $column) => $built['totals'][$column['key']] ?? null,
                    $built['columns'],
                ));
            }

            fclose($handle);
        }, $name.'.csv', ['Content-Type' => 'text/csv']);
    }
}
