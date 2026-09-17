<?php

namespace App\Http\Controllers;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\ScanAccuracyReport;
use App\Services\ScannerCorrectionMemory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI & Analytics — how the document scanner is actually performing.
 *
 * Reads across a module rather than maintaining one, and owns no table of its
 * own beyond the measurements, so it belongs with Credentials, 201 File Status
 * and Deployment Readiness rather than under Employee Information.
 *
 * Behind the audit-log gate rather than a new permission: this is the same
 * class of thing — a record of what the system and its users did, kept for
 * review — and inventing a second permission for it would give two answers to
 * one question.
 */
class ScanAccuracyController extends Controller
{
    public function index(Request $request, ScanAccuracyReport $report, ScannerCorrectionMemory $memory): Response
    {
        Gate::authorize('viewAuditLog', Setting::class);

        [$from, $to] = $this->range($request);

        $scans = DocumentScan::with('employee:id,first_name,middle_name,last_name,suffix')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        return Inertia::render('HR/Analytics/ScanAccuracy', [
            'report' => $report->build($scans),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'driver' => config('scanner.driver'),
            // What the figures are measured against, so the screen can say
            // which fields are in scope rather than leaving the reader to
            // wonder why a document number is not counted.
            'comparedFields' => DocumentScan::COMPARED,
            'employees' => Employee::count(),

            /*
             * What the corrections on this screen have taught the classifier.
             * It belongs here rather than on its own screen for the same
             * reason the by-source table does: this is where the scanner is
             * measured, and a rule the system wrote for itself is exactly the
             * thing somebody should be able to read and disagree with.
             */
            'learning' => [
                'enabled' => $memory->isEnabled(),
                'min_confirmations' => (int) config('scanner.learning.min_confirmations', 2),
                'rules' => $memory->rules()->values(),
            ],
        ]);
    }

    /**
     * Defaults to the last ninety days.
     *
     * Wider than the month the other screens use, deliberately: this is a
     * measurement, and a measurement wants a sample. A month of a small
     * agency's uploads is a handful of scans, and a rate over a handful is
     * noise being reported as a finding.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->date('to') ?? Carbon::today();
        $from = $request->date('from') ?? $to->copy()->subDays(90);

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
