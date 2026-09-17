<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
use App\Services\BulkDocumentFiler;
use App\Services\DocumentScanner;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Filing a batch with nobody looking at it.
 *
 * The batch filer used to propose everything and write nothing until a person
 * had touched all forty rows. It now files the readings every check agrees on
 * and hands back the rest — which is a change in where a person's attention is
 * spent, not in how much the model is trusted. Attention spread evenly over
 * forty rows is attention nobody is really paying by row thirty.
 *
 * So most of this class is about the gates, because the gates are the whole
 * argument. Each one below is a failure this scanner has actually produced,
 * and each *holds* rather than refuses: a held document lands in the review
 * table it always did.
 */
class DocumentAutoFilingTest extends TestCase
{
    use RefreshDatabase;

    /** A heading only an LTO licence carries, so the type is settled by the paper. */
    private const LICENCE_HEADING = 'REPUBLIC OF THE PHILIPPINES · LAND TRANSPORTATION OFFICE · NON-PROFESSIONAL DRIVER\'S LICENSE';

    /*
     * -----------------------------------------------------------------
     * What files itself
     * -----------------------------------------------------------------
     */

    public function test_a_reading_every_check_agrees_on_is_filed_without_anybody(): void
    {
        $juan = $this->driver();

        $result = $this->filerReading([$this->cleanLicence()])
            ->process([$this->image()], Employee::all());

        $this->assertSame(1, $result['filed']);
        $this->assertSame([], $result['documents'], 'a filed row must not come back for review');

        $document = $juan->documents()->sole();

        $this->assertSame('drivers_license', $document->type);
        $this->assertTrue(
            $document->filed_automatically,
            'an auto-filed row has to be tellable from a hand-typed one afterwards',
        );
    }

    /**
     * The batch is mixed in practice, and the reading is "18 went in, 2 need
     * you" — so the two have to come back together rather than the screen
     * simply showing a shorter list.
     */
    public function test_a_mixed_batch_files_what_it_can_and_returns_the_rest(): void
    {
        $this->driver();

        $result = $this->filerReading([
            $this->cleanLicence(),
            // Nobody on file, so there is nothing to file it against.
            ['type' => 'clearance', 'title' => 'NBI CLEARANCE', 'name_on_document' => 'NOBODY AT ALL'],
            $this->cleanLicence(),
        ])->process([$this->image('a.jpg'), $this->image('b.jpg'), $this->image('c.jpg')], Employee::all());

        $this->assertSame(2, $result['filed']);
        $this->assertCount(1, $result['documents']);

        /*
         * The held row keeps its position in the batch the browser still
         * holds. It is what lets the review screen re-upload exactly that
         * file — renumbered here, it would send image 0 and file it against
         * the answer given for image 1.
         */
        $this->assertSame(1, $result['documents'][0]['index']);
        $this->assertSame('b.jpg', $result['documents'][0]['file_name']);
    }

    /*
     * -----------------------------------------------------------------
     * What is held, and why
     * -----------------------------------------------------------------
     */

    /**
     * The three weaker type sources are exactly the ones measured wrong — a
     * number's shape is shared between cards, a validity period overlaps
     * between types, and the model's own key was wrong 4/4 on an NBI clearance
     * whose heading it had transcribed correctly every time.
     */
    public function test_a_type_the_document_did_not_state_is_held(): void
    {
        $this->driver();

        $result = $this->filerReading([[
            // No heading, so the type can only come from the model's own key.
            'type' => 'clearance',
            'name_on_document' => 'DELA CRUZ, JUAN',
            'document_number' => 'N01-23-456789',
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('inferred', $result);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    /**
     * `CredentialExpiryScanner` reads `expires_at`. A licence filed with a
     * null date never appears in a renewal queue — invisible rather than
     * wrong, which is worse.
     */
    public function test_an_expiring_type_with_no_date_read_is_held(): void
    {
        $this->driver();

        $result = $this->filerReading([
            ['expires_at' => null] + $this->cleanLicence(),
        ])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('expires and no expiry date', $result);
    }

    /**
     * Filing an expired document is legitimate and happens often — for the
     * record, or mid-renewal — which is why the upload form reports it rather
     * than refusing it. It is never the thing to do silently.
     */
    public function test_an_expired_document_is_held(): void
    {
        $this->driver();

        $result = $this->filerReading([
            ['issued_at' => '2019-01-05', 'expires_at' => '2024-01-05'] + $this->cleanLicence(),
        ])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('already expired', $result);
    }

    /**
     * The one case a number match cannot catch by itself: a number keyed
     * against the wrong person puts somebody else's licence in this file.
     */
    public function test_a_name_that_contradicts_the_number_is_held(): void
    {
        $this->driver();
        Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $result = $this->filerReading([
            ['name_on_document' => 'SANTOS, MARIA'] + $this->cleanLicence(),
        ])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('somebody else', $result);
    }

    public function test_a_document_nobody_matches_is_held(): void
    {
        $this->driver();

        $result = $this->filerReading([[
            'type' => 'clearance',
            'title' => 'NATIONAL BUREAU OF INVESTIGATION CLEARANCE',
            'name_on_document' => 'NOBODY AT ALL',
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('No employee on file matches', $result);
    }

    /** Taking the first would file the document under a coin toss. */
    public function test_two_people_fitting_one_name_is_held(): void
    {
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $result = $this->filerReading([[
            'type' => 'clearance',
            'title' => 'NATIONAL BUREAU OF INVESTIGATION CLEARANCE',
            'name_on_document' => 'DELA CRUZ, JUAN',
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('More than one employee', $result);
    }

    public function test_a_file_the_scanner_could_not_read_is_held(): void
    {
        $this->driver();

        // A null reading is what a failed model call looks like.
        $result = $this->filerReading([null])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('could not read', $result);
    }

    /*
     * -----------------------------------------------------------------
     * The switch, and the line that does not move
     * -----------------------------------------------------------------
     */

    public function test_turning_it_off_restores_the_old_behaviour_exactly(): void
    {
        $this->driver();
        config(['scanner.autofile.enabled' => false]);

        $result = $this->filerReading([$this->cleanLicence()])
            ->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertCount(1, $result['documents'], 'every row comes back for review');
        $this->assertDatabaseCount('employee_documents', 0);
    }

    /**
     * Narrowing `match_strengths` is the one-line way to make this stricter,
     * and it is the first thing to reach for if a real batch ever files
     * something wrong. Asserted so it stays a working switch rather than a
     * comment.
     */
    public function test_a_name_match_can_be_shut_out_of_automatic_filing(): void
    {
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $reading = [
            'type' => 'clearance',
            'title' => 'NATIONAL BUREAU OF INVESTIGATION CLEARANCE',
            'name_on_document' => 'DELA CRUZ, JUAN',
            'issued_at' => '2026-01-05',
            'expires_at' => '2027-01-05',
        ];

        $this->assertSame(
            1,
            $this->filerReading([$reading])->process([$this->image()], Employee::all())['filed'],
        );

        config(['scanner.autofile.match_strengths' => ['number']]);

        $result = $this->filerReading([$reading])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('Matched by name only', $result);
    }

    /**
     * A hand-filed document must never read as one the system decided on.
     * Nothing else in the app sets the flag, and the default has to hold for
     * every path that existed before this did.
     */
    public function test_a_document_filed_by_hand_is_not_marked_automatic(): void
    {
        Storage::fake('local');

        $employee = Employee::factory()->create();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_HR_STAFF]));

        app(EmployeeService::class)->storeDocument($employee, [
            'type' => 'contract',
            'title' => 'Employment Contract',
        ], $this->image('contract.jpg'));

        $this->assertFalse($employee->documents()->sole()->filed_automatically);
    }

    /*
     * -----------------------------------------------------------------
     * Helpers
     * -----------------------------------------------------------------
     */

    /** @param  array{filed: int, documents: array<int, array<string, mixed>>}  $result */
    /*
     * -----------------------------------------------------------------
     * The two gates the anomaly and ID checks added
     * -----------------------------------------------------------------
     */

    /**
     * A reading that argues with itself is held, not filed.
     *
     * On the upload form a future issue date is a warning somebody reads and
     * decides about. Unattended there is nobody to read it, and filing the
     * reading would store the contradiction as a fact — so the row goes to the
     * review table with the anomaly quoted, rather than with "held" and no
     * cause.
     */
    public function test_a_document_whose_dates_contradict_themselves_is_held(): void
    {
        $this->driver();

        $result = $this->filerReading([[
            ...$this->cleanLicence(),
            'issued_at' => now()->addYear()->toDateString(),
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('future', $result);
    }

    public function test_the_anomaly_gate_can_be_switched_off(): void
    {
        config(['scanner.autofile.hold_anomalies' => false]);
        $this->driver();

        $result = $this->filerReading([[
            ...$this->cleanLicence(),
            'issued_at' => now()->addYear()->toDateString(),
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(1, $result['filed'], 'with the gate off the reading files as before');
    }

    /**
     * A government ID number that is not the shape its agency prints.
     *
     * HR keys real numbers that fail a format rule, which is why the form only
     * warns — but with nobody looking, a malformed number is as likely to be a
     * misread digit as a real one.
     */
    public function test_a_government_id_with_a_malformed_number_is_held(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'suffix' => null,
            'sss_number' => '3412345678',
        ]);

        $result = $this->filerReading([[
            'type' => 'government_id',
            'title' => 'REPUBLIC OF THE PHILIPPINES · SOCIAL SECURITY SYSTEM',
            'name_on_document' => 'DELA CRUZ, JUAN',
            // Eight digits where SSS prints ten.
            'document_number' => '34123456',
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(0, $result['filed']);
        $this->assertHeldFor('SSS', $result);
        $this->assertSame(0, $employee->documents()->count());
    }

    /** The same card with the number it really carries files itself. */
    public function test_a_well_formed_government_id_still_files(): void
    {
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'suffix' => null,
            'sss_number' => '3412345678',
        ]);

        $result = $this->filerReading([[
            'type' => 'government_id',
            'title' => 'REPUBLIC OF THE PHILIPPINES · SOCIAL SECURITY SYSTEM',
            'name_on_document' => 'DELA CRUZ, JUAN',
            'document_number' => '34-1234567-8',
        ]])->process([$this->image()], Employee::all());

        $this->assertSame(1, $result['filed']);
        $this->assertTrue($employee->documents()->sole()->filed_automatically);
    }

    private function assertHeldFor(string $fragment, array $result): void
    {
        $reasons = implode(' | ', $result['documents'][0]['held_for'] ?? []);

        $this->assertFalse($result['documents'][0]['auto']);
        $this->assertStringContainsString(
            $fragment,
            $reasons,
            'a held row has to say why — "held" with no cause sends somebody '
            ."to work out what the system already knows. Got: {$reasons}",
        );
    }

    /** The clean case: heading names the type, the number is on file, dates hold. */
    private function cleanLicence(): array
    {
        return [
            'type' => 'drivers_license',
            'title' => self::LICENCE_HEADING,
            'name_on_document' => 'DELA CRUZ, JUAN',
            'document_number' => 'LICENSE NO.: N01-23-456789',
            'issued_at' => '2026-01-05',
            'expires_at' => '2030-01-05',
        ];
    }

    private function driver(): Employee
    {
        return Employee::factory()->create([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'suffix' => null,
            'drivers_license_number' => 'N01-23-456789',
        ]);
    }

    /** @param  array<int, array<string, mixed>|null>  $readings */
    private function filerReading(array $readings): BulkDocumentFiler
    {
        Storage::fake('local');

        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        // Somebody has to be signed in: storeDocument() stamps uploaded_by,
        // which stays the person who fed the batch through even when the
        // system decided the row.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_HR_STAFF]));

        $scanner = new class($readings) extends DocumentScanner
        {
            private int $call = 0;

            public function __construct(private readonly array $readings)
            {
                parent::__construct();
            }

            protected function read(UploadedFile $file): ?array
            {
                return $this->readings[$this->call++] ?? null;
            }
        };

        return new BulkDocumentFiler($scanner, app(EmployeeService::class));
    }

    private function image(string $name = 'scan.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }
}
