<?php

namespace Tests\Feature;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\ScannerCorrectionMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard's Document Scanner card.
 *
 * The scanner was the only feature in the system with no presence on the
 * landing page, which made how well it reads a document the one figure nobody
 * checked unless they went looking. These tests hold the two things that make
 * the card worth having rather than decorative: that its figures are the
 * Scanner Accuracy screen's own, and that it is not drawn for somebody who may
 * not open the screen behind it.
 */
class DashboardScannerCardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The clean rate is the headline of the Scanner Accuracy screen, and this
     * card has to report the same one. Two scans, one read correctly and one
     * typed over, is 50%.
     */
    public function test_the_card_reports_the_clean_rate_the_accuracy_screen_reports(): void
    {
        $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'clearance']);
        $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'medical']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.scans', 2)
                // 50, not 50.0: `round()` hands back a float and the JSON
                // payload serialises a whole one without its decimal, so the
                // assertion compares against what actually crossed the wire.
                ->where('scannerSummary.clean_rate', 50)
                ->where('scannerSummary.corrected', 1),
            );
    }

    /**
     * Null, not zero.
     *
     * A scanner nothing has been filed through has not been measured, and "0%"
     * would report one that has never been wrong as one that is never right —
     * on the first screen a fresh install ever shows.
     */
    public function test_a_scanner_nothing_has_been_filed_through_reports_no_rate(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.clean_rate', null)
                ->where('scannerSummary.scans', 0),
            );
    }

    /**
     * The "train your AI" figure — what HR's corrections have taught the
     * classifier. Two confirmations is `min_confirmations`, which is the point
     * at which a filing stops being an event and becomes a rule.
     */
    public function test_the_card_counts_what_the_corrections_have_taught_it(): void
    {
        foreach (range(1, 2) as $ignored) {
            $this->scan(
                proposed: ['type' => 'resume', 'heading' => 'BUREAU OF INTERNAL REVENUE TIN ID'],
                saved: ['type' => 'government_id'],
            );
        }

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.learning_enabled', true)
                ->where('scannerSummary.learned', 1),
            );
    }

    /**
     * Null rather than a count when the feedback is switched off.
     *
     * `SCANNER_LEARNING=false` still measures, so the rules stay derivable
     * while nothing reads them — and a tile saying the system learned something
     * it is applying none of is the card telling a lie about itself.
     */
    public function test_a_switched_off_memory_reports_no_learned_count(): void
    {
        config(['scanner.learning.enabled' => false]);

        $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'clearance']);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.learning_enabled', false)
                ->where('scannerSummary.learned', null),
            );
    }

    /**
     * The card links to a screen behind `viewAuditLog`, so an employee gets no
     * card rather than a link into a 403 — which would tell them there is
     * something there *and* that they are not trusted with it.
     */
    public function test_an_employee_is_not_shown_the_card_at_all(): void
    {
        $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'clearance']);

        $employee = Employee::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $employee->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scannerSummary', null));
    }

    /** A supervisor is no more entitled to the measurement than an employee. */
    public function test_a_supervisor_is_not_shown_the_card_either(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPERVISOR]))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scannerSummary', null));
    }

    /**
     * With no key and no scans there is no feature to report on, so the card is
     * absent rather than drawn as a row of dashes. A dark feature is not a
     * broken one — but a card of dashes is how a reader concludes it is.
     */
    public function test_a_scanner_that_was_never_configured_draws_no_card(): void
    {
        config(['scanner.gemini.api_key' => null]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scannerSummary', null));
    }

    /**
     * Scans on record with the driver since switched off still draw: the
     * measurement is history and stays worth reading.
     */
    public function test_scans_on_record_still_draw_once_the_driver_is_switched_off(): void
    {
        $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'clearance']);

        config(['scanner.gemini.api_key' => null]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scannerSummary.scans', 1));
    }

    /**
     * The window is `scanner.accuracy.default_days`, shared with the Scanner
     * Accuracy screen so the two cannot measure different months and report two
     * clean rates for one scanner.
     */
    public function test_a_scan_older_than_the_window_is_outside_the_figures(): void
    {
        config(['scanner.accuracy.default_days' => 30]);

        $old = $this->scan(proposed: ['type' => 'clearance'], saved: ['type' => 'clearance']);
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.days', 30)
                ->where('scannerSummary.scans', 0),
            );
    }

    /**
     * The preview line names the document, because "Corrected" on its own says
     * a person disagreed without saying about what.
     */
    public function test_the_preview_names_what_the_most_recent_scan_settled_on(): void
    {
        $this->scan(
            proposed: ['type' => 'resume'],
            saved: ['type' => 'government_id'],
        );

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scannerSummary.latest.outcome', 'corrected')
                ->where('scannerSummary.latest.subtitle', 'Corrected by HR — filed as Government ID'),
            );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The derived rules are cached for ten minutes, and a test that reads
        // them after another test has warmed the cache would assert the
        // previous test's rows.
        app(ScannerCorrectionMemory::class)->forget();

        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);
    }

    /**
     * One scan, proposed and then filed.
     *
     * `recordOutcome()` is what works out which proposals were kept and which
     * were typed over, so the row is built the way a real upload builds it
     * rather than by writing `accepted` and `corrected` in by hand — which
     * would test this file's idea of a correction instead of the model's.
     */
    private function scan(array $proposed, ?array $saved = null): DocumentScan
    {
        $employee = Employee::factory()->create();

        $scan = DocumentScan::create([
            'employee_id' => $employee->id,
            'driver' => 'gemini',
            'model' => 'gemini-3.6-flash',
            'duration_ms' => 2000,
            'proposed' => $proposed,
        ]);

        if ($saved !== null) {
            $scan->recordOutcome($this->document($employee), $saved);
        }

        return $scan;
    }

    private function document(Employee $employee): EmployeeDocument
    {
        Storage::fake('local');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => 'clearance',
            'title' => 'Scanned document',
            'file_path' => 'x.jpg',
            'file_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1,
        ]);
    }
}
