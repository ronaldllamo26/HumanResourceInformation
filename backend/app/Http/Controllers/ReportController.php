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

        abort_unless(in_array($format, ['pdf', 'csv', 'excel', 'xlsx'], true), 404);

        [$report, $filters] = $this->requested($request);
        $built = $this->reports->build($report, $filters);

        // After the gate and before the bytes: a refused request is not an access.
        $this->logger->exported($report, Employee::class, [
            'format' => $format,
            'rows' => count($built['rows']),
            'filters' => array_filter($filters, fn ($value) => $value !== null),
        ]);

        $name = $report.'-'.now()->format('Ymd-His');

        return match ($format) {
            'pdf' => $this->pdf($built, $name),
            'csv' => $this->csv($built, $name),
            'excel', 'xlsx' => $this->excel($built, $name),
        };
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
        $logoPath = public_path('images/logo.png');
        $logo = file_exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath))
            : null;

        // Landscape, because a report is wide: the attendance summary is ten
        // columns and portrait would print it as a column of fragments.
        return Pdf::loadView('reports.pdf', [
            'report' => $built,
            'company' => Setting::get('company.name', config('app.name')),
            'printedBy' => request()->user()?->name,
            'logo' => $logo,
        ])
            ->setPaper('a4', 'landscape')
            ->download($name.'.pdf');
    }

    /** @param array<string, mixed> $built */
    private function excel(array $built, string $name): HttpResponse
    {
        $company = Setting::get('company.name', config('app.name'));
        $generatedAt = now()->format('M j, Y g:i A');
        $user = request()->user()?->name ?? 'System';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
        $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"'."\n";
        $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"'."\n";
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'."\n";
        $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">'."\n";

        $xml .= '<Styles>'."\n";
        $xml .= ' <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#111827"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Company"><Font ss:FontName="Segoe UI" ss:Size="14" ss:Bold="1" ss:Color="#1E3A8A"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Title"><Font ss:FontName="Segoe UI" ss:Size="12" ss:Bold="1" ss:Color="#1F2937"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Subtitle"><Font ss:FontName="Segoe UI" ss:Size="10" ss:Italic="1" ss:Color="#4B5563"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Meta"><Font ss:FontName="Segoe UI" ss:Size="9" ss:Color="#6B7280"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Header"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/></Borders><Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E3A8A" ss:Pattern="Solid"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Data"><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders><Font ss:FontName="Segoe UI" ss:Size="10"/></Style>'."\n";
        $xml .= ' <Style ss:ID="DataRight"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders><Font ss:FontName="Segoe UI" ss:Size="10"/></Style>'."\n";
        $xml .= ' <Style ss:ID="Totals"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Double" ss:Weight="3" ss:Color="#1E3A8A"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/></Borders><Font ss:FontName="Segoe UI" ss:Size="10" ss:Bold="1" ss:Color="#1E3A8A"/><Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/></Style>'."\n";
        $xml .= '</Styles>'."\n";

        $xml .= '<Worksheet ss:Name="Report">'."\n";
        $xml .= ' <Table ss:DefaultRowHeight="20">'."\n";

        foreach ($built['columns'] as $col) {
            $xml .= '  <Column ss:AutoFitWidth="1" ss:Width="120"/>'."\n";
        }

        // Title and header information
        $xml .= '  <Row ss:Height="24"><Cell ss:StyleID="Company"><Data ss:Type="String">'.htmlspecialchars((string) $company, ENT_XML1).'</Data></Cell></Row>'."\n";
        $xml .= '  <Row ss:Height="20"><Cell ss:StyleID="Title"><Data ss:Type="String">'.htmlspecialchars((string) $built['title'], ENT_XML1).'</Data></Cell></Row>'."\n";
        $xml .= '  <Row ss:Height="18"><Cell ss:StyleID="Subtitle"><Data ss:Type="String">'.htmlspecialchars((string) $built['subtitle'], ENT_XML1).'</Data></Cell></Row>'."\n";
        $xml .= '  <Row ss:Height="16"><Cell ss:StyleID="Meta"><Data ss:Type="String">Generated: '.htmlspecialchars($generatedAt, ENT_XML1).' by '.htmlspecialchars($user, ENT_XML1).' | Total Records: '.count($built['rows']).'</Data></Cell></Row>'."\n";
        $xml .= '  <Row ss:Height="10"/>'."\n";

        // Table headers
        $xml .= '  <Row ss:Height="24">'."\n";
        foreach ($built['columns'] as $col) {
            $xml .= '   <Cell ss:StyleID="Header"><Data ss:Type="String">'.htmlspecialchars((string) $col['label'], ENT_XML1).'</Data></Cell>'."\n";
        }
        $xml .= '  </Row>'."\n";

        // Table rows
        foreach ($built['rows'] as $row) {
            $xml .= '  <Row ss:Height="19">'."\n";
            foreach ($built['columns'] as $col) {
                $val = $row[$col['key']] ?? '';
                $isRight = ($col['align'] ?? null) === 'right';
                $styleId = $isRight ? 'DataRight' : 'Data';
                $type = is_numeric($val) && ! str_starts_with((string) $val, '0') ? 'Number' : 'String';
                $cleanVal = htmlspecialchars((string) ($val !== '' ? $val : '—'), ENT_XML1);
                $xml .= '   <Cell ss:StyleID="'.$styleId.'"><Data ss:Type="'.$type.'">'.$cleanVal.'</Data></Cell>'."\n";
            }
            $xml .= '  </Row>'."\n";
        }

        // Totals row
        if (! empty($built['totals'])) {
            $xml .= '  <Row ss:Height="22">'."\n";
            foreach ($built['columns'] as $col) {
                $val = $built['totals'][$col['key']] ?? '';
                $cleanVal = htmlspecialchars((string) $val, ENT_XML1);
                $type = is_numeric($val) ? 'Number' : 'String';
                $xml .= '   <Cell ss:StyleID="Totals"><Data ss:Type="'.$type.'">'.$cleanVal.'</Data></Cell>'."\n";
            }
            $xml .= '  </Row>'."\n";
        }

        $xml .= ' </Table>'."\n";
        $xml .= '</Worksheet>'."\n";
        $xml .= '</Workbook>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.xls"',
            'Cache-Control' => 'max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
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
