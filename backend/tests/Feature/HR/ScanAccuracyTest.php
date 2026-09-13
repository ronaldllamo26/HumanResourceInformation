<?php

namespace Tests\Feature\HR;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\ScanAccuracyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Measuring the scanner instead of asking it how it did.
 *
 * The model reports a confidence on every scan and that field is useless —
 * asked across six documents it answered "high" six times, including on the
 * readings that were wrong. So accuracy is taken from the one thing that
 * carries information: whether HR kept the value or typed over it.
 */
class ScanAccuracyTest extends TestCase
{
    use RefreshDatabase;

    // --- Recording ------------------------------------------------------------

    /**
     * The proposal is written when the scan runs, not when an upload succeeds.
     * A scan somebody then abandoned is a real outcome, and counting only the
     * ones that ended in a filed document would flatter every figure.
     */
    public function test_a_scan_is_recorded_even_when_no_document_follows(): void
    {
        $this->scannerAnswering(['type' => 'clearance', 'title' => 'NBI Clearance']);

        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->postJson("/hr/employees/{$employee->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertJsonPath('scanned', true);

        // Written, and waiting: no upload followed, so it stays unlinked and
        // counts as abandoned rather than disappearing from the figures.
        $this->assertDatabaseCount('document_scans', 1);
        $this->assertNull(DocumentScan::first()->employee_document_id);
    }

    /** A failed call has no proposal, so there is nothing to measure. */
    public function test_a_failed_scan_records_nothing(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);
        Http::fake(['*' => Http::response('model not found', 404)]);

        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->postJson("/hr/employees/{$employee->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertJsonPath('scanned', false)
            ->assertJsonPath('scan_id', null);

        $this->assertDatabaseCount('document_scans', 0);
    }

    // --- Comparing -------------------------------------------------------------

    public function test_it_separates_the_fields_kept_from_the_fields_corrected(): void
    {
        $scan = $this->scanProposing([
            'type' => 'clearance',
            'title' => 'Clearance',
            'issued_at' => '2026-03-14',
            'expires_at' => '2027-03-14',
        ]);

        $scan->recordOutcome($this->document($scan), [
            'type' => 'clearance',                 // kept
            'title' => 'NBI Clearance 2026',       // corrected
            'issued_at' => '2026-03-14',           // kept
            'expires_at' => '2027-03-14',          // kept
        ]);

        $scan->refresh();

        $this->assertSame(['type', 'issued_at', 'expires_at'], $scan->accepted);
        $this->assertSame(['title'], $scan->corrected);
    }

    /**
     * Whitespace and case are not corrections. Counting them would understate
     * the scanner by rewarding a trailing space.
     */
    public function test_case_and_whitespace_are_not_counted_as_corrections(): void
    {
        $scan = $this->scanProposing(['type' => 'clearance', 'title' => 'Clearance']);

        $scan->recordOutcome($this->document($scan), [
            'type' => 'CLEARANCE',
            'title' => '  clearance  ',
        ]);

        $this->assertSame([], $scan->refresh()->corrected);
    }

    /**
     * A field the scanner never proposed is outside the measurement — there
     * was nothing to get wrong, and counting it as a correction would blame
     * the scanner for a value it never offered.
     */
    public function test_a_field_that_was_never_proposed_is_not_counted(): void
    {
        $scan = $this->scanProposing(['type' => 'clearance', 'expires_at' => null]);

        $scan->recordOutcome($this->document($scan), [
            'type' => 'clearance',
            'expires_at' => '2027-03-14',
        ]);

        $scan->refresh();

        $this->assertSame(['type'], $scan->accepted);
        $this->assertSame([], $scan->corrected);
    }

    // --- Reporting -------------------------------------------------------------

    public function test_the_report_counts_clean_scans_and_field_rates(): void
    {
        $clean = $this->scanProposing(['type' => 'clearance', 'title' => 'Clearance']);
        $clean->recordOutcome($this->document($clean), ['type' => 'clearance', 'title' => 'Clearance']);

        $fixed = $this->scanProposing(['type' => 'clearance', 'title' => 'Clearance']);
        $fixed->recordOutcome($this->document($fixed), ['type' => 'medical', 'title' => 'Clearance']);

        $report = app(ScanAccuracyReport::class)->build(DocumentScan::all());

        $this->assertSame(2, $report['totals']['filed']);
        $this->assertSame(1, $report['totals']['clean']);
        $this->assertSame(50.0, $report['totals']['clean_rate']);
        // Four fields offered across two scans; three kept.
        $this->assertSame(75.0, $report['totals']['field_rate']);
    }

    /** An abandoned scan drags the figures down, because it is a failure. */
    public function test_an_abandoned_scan_is_counted_in_the_total(): void
    {
        $this->scanProposing(['type' => 'clearance']);

        $report = app(ScanAccuracyReport::class)->build(DocumentScan::all());

        $this->assertSame(1, $report['totals']['scans']);
        $this->assertSame(1, $report['totals']['abandoned']);
        $this->assertSame(0, $report['totals']['filed']);
        $this->assertNull($report['totals']['clean_rate'], 'No filed scans is not a rate of zero.');
    }

    /** Worst field first — the screen exists to show where to look. */
    public function test_fields_are_reported_worst_first(): void
    {
        foreach (range(1, 3) as $i) {
            $scan = $this->scanProposing(['type' => 'clearance', 'title' => 'Clearance']);
            $scan->recordOutcome($this->document($scan), [
                'type' => 'clearance',
                'title' => "Something else {$i}",
            ]);
        }

        $report = app(ScanAccuracyReport::class)->build(DocumentScan::all());

        $this->assertSame('title', $report['fields'][0]['field']);
        $this->assertSame(0.0, $report['fields'][0]['rate']);
    }

    /**
     * The five type sources are ordered by an argument; this is where it is
     * checked against outcomes rather than asserted.
     */
    public function test_it_reports_how_each_type_source_performed(): void
    {
        $heading = $this->scanProposing(['type' => 'clearance', 'type_source' => 'heading']);
        $heading->recordOutcome($this->document($heading), ['type' => 'clearance']);

        $guess = $this->scanProposing(['type' => 'clearance', 'type_source' => 'model']);
        $guess->recordOutcome($this->document($guess), ['type' => 'medical']);

        $report = app(ScanAccuracyReport::class)->build(DocumentScan::all());

        $this->assertSame('heading', $report['sources'][0]['source']);
        $this->assertSame(100.0, $report['sources'][0]['rate']);
        $this->assertSame(0.0, $report['sources'][1]['rate']);
    }

    /**
     * The median, not the mean: the first scan after a reboot loads the model
     * into VRAM and takes forty seconds, and one of those misdescribes every
     * other scan in the set.
     */
    public function test_the_duration_reported_is_the_median(): void
    {
        foreach ([3000, 3200, 40000] as $ms) {
            $this->scanProposing(['type' => 'clearance'], $ms);
        }

        $report = app(ScanAccuracyReport::class)->build(DocumentScan::all());

        $this->assertSame(3200, $report['totals']['median_ms']);
    }

    // --- The way in -------------------------------------------------------------

    public function test_the_screen_is_behind_the_audit_log_gate(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPERVISOR]))
            ->get('/hr/scan-accuracy')
            ->assertForbidden();
    }

    public function test_hr_can_open_it(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/scan-accuracy')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HR/Analytics/ScanAccuracy'));
    }

    // --- Fixtures ----------------------------------------------------------------

    /**
     * A driver that answers, without calling one.
     *
     * `read()` is stubbed rather than faked at the HTTP layer, because the
     * thing under test here is what the accuracy figures do with a reading —
     * not how the reading got back. A test suite must not ask the network
     * what it thinks; when it did, one of these took fourteen seconds and
     * depended on whatever model happened to be loaded.
     */
    private function scannerAnswering(array $reading): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        Http::fake(['*' => Http::response(['response' => json_encode($reading)])]);
    }

    private function scanProposing(array $proposed, int $ms = 3000): DocumentScan
    {
        return DocumentScan::create([
            'employee_id' => Employee::factory()->create()->id,
            'driver' => 'gemini',
            'model' => 'glm-ocr',
            'duration_ms' => $ms,
            'proposed' => $proposed,
        ]);
    }

    private function document(DocumentScan $scan): EmployeeDocument
    {
        Storage::fake('local');

        return EmployeeDocument::create([
            'employee_id' => $scan->employee_id,
            'type' => 'clearance',
            'title' => 'x',
            'file_path' => 'x.jpg',
            'file_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1,
        ]);
    }
}
