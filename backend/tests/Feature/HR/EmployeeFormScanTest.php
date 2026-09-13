<?php

namespace Tests\Feature\HR;

use App\Models\EmployeeEndorsement;
use App\Models\User;
use App\Services\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Reading a paper 201 form into the Create Employee screen.
 *
 * The CSV import digitises a spreadsheet; this digitises a filing cabinet. It
 * shares the scanner's drivers and its guarantees: nothing is written, every
 * value is checked in PHP against the lists StoreEmployeeRequest validates
 * against, and a null leaves the field alone.
 *
 * `readForm()` is stubbed here for the same reason `read()` is — the rules are
 * the thing worth testing, and they should not need a network call.
 */
class EmployeeFormScanTest extends TestCase
{
    use RefreshDatabase;

    // --- Untrusted input ----------------------------------------------------

    public function test_it_reads_a_form_into_employee_fields(): void
    {
        $fields = $this->read([
            'last_name' => 'BENAVIDEZ',
            'first_name' => 'JOHN GAVE',
            'birth_date' => 'March 24, 1997',
            'gender' => 'Male',
            'civil_status' => 'Single',
        ]);

        $this->assertSame('BENAVIDEZ', $fields['last_name']);
        $this->assertSame('1997-03-24', $fields['birth_date']);
        $this->assertSame('male', $fields['gender']);
        $this->assertSame('single', $fields['civil_status']);
    }

    /**
     * A value outside the list becomes null rather than the nearest match.
     * Filling the form with something the save would then refuse is worse
     * than leaving the field for HR.
     */
    public function test_an_enum_the_form_does_not_accept_becomes_null(): void
    {
        $fields = $this->read(['gender' => 'M', 'civil_status' => 'live-in']);

        $this->assertNull($fields['gender']);
        $this->assertNull($fields['civil_status']);
    }

    /** A birth date in the future is a misread year, never a person. */
    public function test_a_future_birth_date_is_dropped(): void
    {
        $this->assertNull($this->read(['birth_date' => now()->addYear()->toDateString()])['birth_date']);
    }

    public function test_an_unreadable_email_is_dropped_rather_than_filled(): void
    {
        $this->assertNull($this->read(['email' => 'jgave at example com'])['email']);
        $this->assertSame('a@b.test', $this->read(['email' => 'a@b.test'])['email']);
    }

    /** The same caption-stripping the ID scanner uses, for the same reason. */
    public function test_a_caption_is_stripped_from_a_government_number(): void
    {
        $this->assertSame(
            '34-1234567-8',
            $this->read(['sss_number' => 'SSS NO.: 34-1234567-8'])['sss_number'],
        );
    }

    // --- The bleed guard ----------------------------------------------------

    /**
     * The measured failure. On a sheet with no RELIGION and no PERMANENT
     * ADDRESS the model answered "Mother" — the emergency contact's
     * relationship — and "34-1234567-8", the SSS number one line above.
     * Both were repeatable, and both are fields the form does not have.
     */
    public function test_a_value_copied_from_a_neighbouring_field_is_dropped(): void
    {
        $fields = $this->read([
            'religion' => 'Mother',
            'emergency_contact_relationship' => 'Mother',
            'permanent_address' => '34-1234567-8',
            'sss_number' => '34-1234567-8',
        ]);

        $this->assertNull($fields['religion'], 'religion was copied from the relationship');
        $this->assertNull($fields['permanent_address'], 'the address was an SSS number');
        $this->assertSame('Mother', $fields['emergency_contact_relationship']);
        $this->assertSame('34-1234567-8', $fields['sss_number']);
    }

    /** An address is words. A line that is all digits is somebody's ID number. */
    public function test_an_address_that_is_really_a_number_is_dropped(): void
    {
        $fields = $this->read([
            'present_address' => '1234-5678-9012',
            'permanent_address' => '12 Mabini St., Quezon City',
        ]);

        $this->assertNull($fields['present_address']);
        $this->assertSame('12 Mabini St., Quezon City', $fields['permanent_address']);
    }

    /** "Same as present address" is what most people write — not a bleed. */
    public function test_a_permanent_address_may_repeat_the_present_one(): void
    {
        $fields = $this->read([
            'present_address' => '12 Mabini St., Quezon City',
            'permanent_address' => '12 Mabini St., Quezon City',
        ]);

        $this->assertSame('12 Mabini St., Quezon City', $fields['permanent_address']);
    }

    // --- The way in ---------------------------------------------------------

    public function test_the_endpoint_404s_when_no_scanner_is_configured(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => null]);

        $this->actingAs(User::factory()->hrStaff()->create())
            ->post('/hr/employees/scan-form', ['file' => UploadedFile::fake()->image('201.jpg')])
            ->assertNotFound();
    }

    public function test_an_employee_cannot_scan_a_form(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->post('/hr/employees/scan-form', ['file' => UploadedFile::fake()->image('201.jpg')])
            ->assertForbidden();
    }

    public function test_the_create_screen_hides_the_button_when_the_scanner_is_off(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => null]);

        // The form is only reachable by approving an endorsement, so one has
        // to exist for there to be a screen to assert about.
        $endorsement = EmployeeEndorsement::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanForm', false));
    }

    public function test_the_create_screen_offers_it_when_the_scanner_is_on(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        $endorsement = EmployeeEndorsement::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/create?endorsement={$endorsement->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanForm', true));
    }

    private function scannerReturning(?array $reading): DocumentScanner
    {
        return new class($reading) extends DocumentScanner
        {
            public function __construct(private readonly ?array $reading)
            {
                parent::__construct();
            }

            protected function readForm(UploadedFile $file): ?array
            {
                return $this->reading;
            }
        };
    }

    private function read(array $raw): array
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        return $this->scannerReturning($raw)->scanEmployeeForm(
            UploadedFile::fake()->image('201.jpg'),
        );
    }
}
