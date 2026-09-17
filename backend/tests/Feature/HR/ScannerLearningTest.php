<?php

namespace Tests\Feature\HR;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\User;
use App\Services\ScannerCorrectionMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What HR's corrections teach the classifier.
 *
 * The rules are derived from `document_scans` — the same rows Scanner Accuracy
 * is measured from — and applied in PHP. Nothing is retrained and nothing new
 * is sent to the provider, which is the whole design.
 */
class ScannerLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_heading_filed_the_same_way_twice_becomes_a_rule(): void
    {
        $this->scan('NATIONAL BUREAU OF INVESTIGATION CLEARANCE', 'clearance');
        $this->scan('National Bureau of Investigation Clearance', 'clearance');

        $memory = app(ScannerCorrectionMemory::class);

        $this->assertSame('clearance', $memory->typeFor('NATIONAL BUREAU OF INVESTIGATION CLEARANCE'));
        $this->assertSame(1, $memory->rules()->count());
        $this->assertSame(2, $memory->rules()->first()['confirmations']);
    }

    /** One filing is an event; two is a pattern. The threshold is what stops a single mis-filing teaching the scanner. */
    public function test_one_filing_teaches_nothing(): void
    {
        $this->scan('MEDICAL CERTIFICATE', 'medical');

        $this->assertNull(app(ScannerCorrectionMemory::class)->typeFor('MEDICAL CERTIFICATE'));
    }

    /** Filed as two different types: one of the two is wrong and the values cannot say which. */
    public function test_a_heading_hr_disagreed_about_is_dropped_entirely(): void
    {
        $this->scan('CERTIFICATE', 'medical');
        $this->scan('CERTIFICATE', 'certificate');
        $this->scan('CERTIFICATE', 'medical');

        $this->assertNull(app(ScannerCorrectionMemory::class)->typeFor('CERTIFICATE'));
    }

    /**
     * The model condenses a letterhead differently between scans, so a rule
     * that only matched the identical string would never be used again.
     */
    public function test_a_shortened_heading_still_matches(): void
    {
        $this->scan('BUREAU OF INTERNAL REVENUE TIN ID', 'government_id');
        $this->scan('BUREAU OF INTERNAL REVENUE TIN ID', 'government_id');

        $this->assertSame('government_id', app(ScannerCorrectionMemory::class)->typeFor('TIN ID'));
    }

    /** A "heading" that is really an ID number must not end up in a rule list. */
    public function test_a_heading_carrying_a_long_number_is_never_learned(): void
    {
        $this->scan('N0224001292', 'license');
        $this->scan('N0224001292', 'license');

        $this->assertTrue(app(ScannerCorrectionMemory::class)->rules()->isEmpty());
    }

    public function test_the_switch_turns_it_off_without_touching_the_rows(): void
    {
        $this->scan('NBI CLEARANCE', 'clearance');
        $this->scan('NBI CLEARANCE', 'clearance');

        config(['scanner.learning.enabled' => false]);

        $this->assertNull(app(ScannerCorrectionMemory::class)->typeFor('NBI CLEARANCE'));
        $this->assertSame(2, DocumentScan::count());
    }

    public function test_the_accuracy_screen_shows_what_was_learned(): void
    {
        $this->scan('NBI CLEARANCE', 'clearance');
        $this->scan('NBI CLEARANCE', 'clearance');

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/scan-accuracy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('learning.enabled', true)
                ->where('learning.rules.0.type', 'clearance')
                ->where('learning.rules.0.confirmations', 2));
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(ScannerCorrectionMemory::class)->forget();
    }

    private function scan(string $heading, string $filedAs): DocumentScan
    {
        return DocumentScan::create([
            'employee_id' => Employee::factory()->create()->id,
            'driver' => 'gemini',
            'model' => 'gemini-3.5-flash',
            'proposed' => ['heading' => $heading, 'type' => 'government_id', 'type_source' => 'model'],
            'saved' => ['type' => $filedAs, 'title' => 'x'],
            'accepted' => [],
            'corrected' => ['type'],
        ]);
    }
}
