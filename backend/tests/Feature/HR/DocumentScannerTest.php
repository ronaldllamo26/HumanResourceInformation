<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The AI document scanner reads a 201-file upload and proposes the fields.
 *
 * Everything it returns is treated as untrusted: the document type is checked
 * against EmployeeDocument::TYPES, dates are re-parsed, and the name check is
 * done in PHP rather than asked of the model. These tests cover that layer —
 * `read()` is stubbed, so nothing here needs an API key or a network call.
 */
class DocumentScannerTest extends TestCase
{
    use RefreshDatabase;

    // --- Gating -----------------------------------------------------------

    public function test_the_scanner_is_off_without_an_api_key(): void
    {
        config(['scanner.api_key' => null]);

        $this->assertFalse(app(DocumentScanner::class)->isEnabled());
    }

    /**
     * Every driver is hosted, so a key is the whole configuration in each
     * case — and each reads its *own* key rather than a shared one. A driver
     * that fell back to another's key would draw the Scan button on a
     * misconfiguration and fail on every upload instead of staying dark.
     */
    public function test_each_driver_reads_its_own_key(): void
    {
        config([
            'scanner.driver' => 'openrouter',
            'scanner.openrouter.api_key' => null,
            // Present, and belonging to somebody else.
            'scanner.gemini.api_key' => 'test-key',
            'scanner.api_key' => 'test-key',
        ]);

        $this->assertFalse(app(DocumentScanner::class)->isEnabled());

        config(['scanner.openrouter.api_key' => 'test-key']);

        $this->assertTrue(app(DocumentScanner::class)->isEnabled());
    }

    /**
     * The local driver was removed, and an `.env` still naming it must go
     * dark rather than fall through to a working one.
     *
     * A real upgrade path: every machine that ran this before had
     * `SCANNER_DRIVER=ollama` in its `.env`, and `.env` is not in the repo —
     * so pulling this change leaves the old value in place. Going dark says
     * "the scanner is off" on a screen somebody is looking at; falling
     * through to Gemini would silently start sending 201-file photographs
     * abroad from a machine whose owner never chose that.
     */
    public function test_the_removed_local_driver_goes_dark(): void
    {
        config(['scanner.driver' => 'ollama', 'scanner.gemini.api_key' => 'test-key']);

        $this->assertFalse(app(DocumentScanner::class)->isEnabled());
    }

    // --- The default driver ------------------------------------------------

    /**
     * Gemini is the default and the one to prefer: free at this tier, and a
     * single named processor rather than a broker. The key is the whole
     * configuration.
     */
    public function test_the_gemini_driver_needs_a_key(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => null]);
        $this->assertFalse(app(DocumentScanner::class)->isEnabled());

        config(['scanner.gemini.api_key' => 'test-key']);
        $this->assertTrue(app(DocumentScanner::class)->isEnabled());
    }

    /**
     * The drivers answer the same shape, so everything downstream of read() —
     * the type check, the date pair, the name match — is unchanged by which
     * one ran. A regression here would only show up in production on whichever
     * driver the tests do not exercise.
     */
    public function test_every_driver_returns_the_same_normalised_shape(): void
    {
        $expected = [
            'type', 'type_source', 'type_certain', 'title', 'heading', 'document_number', 'issued_at', 'expires_at',
            'never_expires',
            'name_on_document', 'name_matches', 'number_matches', 'number_format_ok',
            'expiry', 'name_may_differ', 'registry', 'confidence', 'note',
            'dl_codes', 'id_validation', 'anomalies',
        ];

        foreach (['gemini', 'openrouter', 'anthropic'] as $driver) {
            config([
                'scanner.driver' => $driver,
                'scanner.gemini.api_key' => 'test-key',
                'scanner.openrouter.api_key' => 'test-key',
                'scanner.api_key' => 'test-key',
            ]);

            $fields = $this->scannerReturning([
                'type' => 'drivers_license',
                'expires_at' => '2029-05-12',
            ])->scan(UploadedFile::fake()->image('x.jpg'));

            $this->assertSame($expected, array_keys($fields), "Driver {$driver} drifted.");
        }
    }

    /** A typo in SCANNER_DRIVER must go dark, not fall through to a default. */
    public function test_an_unknown_driver_is_off(): void
    {
        config(['scanner.driver' => 'gpt-please', 'scanner.api_key' => 'test-key']);

        $this->assertFalse(app(DocumentScanner::class)->isEnabled());
    }

    // --- Cleaning what the model wrote -------------------------------------

    /**
     * The title comes from the validated type, not from free text. A small
     * local model reliably answers "Last Name, First Name, Middle Name" here
     * — it reads the label above the value.
     */
    public function test_the_title_is_derived_from_the_type_not_from_free_text(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'Last Name, First Name, Middle Name',
        ])->scan(UploadedFile::fake()->image('licence.jpg'));

        $this->assertSame("Driver's Licence", $fields['title']);
    }

    /**
     * The heading beats the enum, because the model is measurably better at
     * transcribing a title than at picking the matching key.
     *
     * Regression guard for a real miss: on an NBI clearance this model writes
     * title "NBI Clearance" and then answers type "drivers_license", every
     * time. Filing that under licences would put a clearance in the wrong
     * renewal window in CredentialExpiryScanner.
     */
    public function test_the_documents_heading_overrules_a_wrong_type(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'NBI Clearance',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame('Clearance', $fields['title']);
    }

    /**
     * The form has to know *where* the type came from, not just what it is.
     *
     * Choosing "Government ID" and uploading a clearance used to save quietly
     * under the wrong type, and a clearance filed as an ID lands in the wrong
     * renewal window — it stops being chased at all. The upload is now refused
     * when the document's own heading contradicts the chosen type.
     *
     * But only then. The model's bare guess is wrong often enough that
     * blocking on it would refuse correct filings, so that case only warns —
     * which is why this flag exists rather than a plain type comparison.
     */
    public function test_a_type_read_from_the_heading_is_marked_as_such(): void
    {
        $fromHeading = $this->scannerReturning([
            'type' => 'government_id',
            'title' => 'NBI Clearance',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fromHeading['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_HEADING, $fromHeading['type_source']);
        $this->assertTrue($fromHeading['type_certain']);
    }

    public function test_a_type_the_model_merely_guessed_is_not(): void
    {
        $guessed = $this->scannerReturning([
            'type' => 'clearance',
            // No keyword in the heading, so nothing corroborates the guess.
            'title' => 'LAST NAME, FIRST NAME, MIDDLE NAME',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $guessed['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_MODEL, $guessed['type_source']);
        $this->assertFalse($guessed['type_certain']);
    }

    /**
     * PSA civil registry documents are their own type, not training
     * certificates.
     *
     * The keyword order in `scanner.title_keywords` is what does this: "PSA
     * Birth Certificate" contains the word "certificate", so `psa` has to be
     * tested first or every birth certificate is filed as a qualification.
     *
     * @dataProvider psaHeadings
     */
    public function test_psa_documents_are_not_filed_as_training_certificates(
        string $heading,
        string $expected,
    ): void {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => $heading,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame($expected, $fields['type'], "\"{$heading}\" was misfiled.");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function psaHeadings(): array
    {
        return [
            'birth certificate' => ['PSA Birth Certificate', 'psa'],
            'certificate of live birth' => ['CERTIFICATE OF LIVE BIRTH', 'psa'],
            'the issuing authority' => ['Philippine Statistics Authority', 'psa'],
            'marriage certificate' => ['Certificate of Marriage', 'psa'],
            'cenomar' => ['CENOMAR', 'psa'],
            // PSA was NSO until 2013, and older copies still say so.
            'an older NSO copy' => ['NSO Birth Certificate', 'psa'],

            // The ones that must keep their own type.
            'a training certificate' => ['TESDA Certificate of Competency', 'certificate'],
            'an NBI clearance' => ['NBI Clearance', 'clearance'],
        ];
    }

    /**
     * The common case for a licence, whose title line is the field caption
     * above the name rather than the document's own heading.
     */
    public function test_a_heading_with_no_keyword_leaves_the_models_type_alone(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'LAST NAME, FIRST NAME, MIDDLE NAME',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('drivers_license', $fields['type']);
    }

    /**
     * Where this model lies most readily: a document carrying no dates at
     * all. Given a PhilSys card, which prints a birth date and nothing else,
     * it answered that same date as both the issue and the expiry — and an
     * invented expiry is the exact failure the whole feature exists to stop.
     */
    public function test_an_expiry_equal_to_the_issue_date_drops_both(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'issued_at' => '1990-07-14',
            'expires_at' => '1990-07-14',
        ])->scan(UploadedFile::fake()->image('philsys.jpg'));

        $this->assertNull($fields['issued_at']);
        $this->assertNull($fields['expires_at']);
    }

    public function test_an_expiry_before_the_issue_date_drops_both(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'issued_at' => '2026-05-01',
            'expires_at' => '2024-05-01',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['issued_at']);
        $this->assertNull($fields['expires_at']);
    }

    public function test_a_sane_pair_of_dates_survives(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'issued_at' => '2024-05-12',
            'expires_at' => '2029-05-12',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('2024-05-12', $fields['issued_at']);
        $this->assertSame('2029-05-12', $fields['expires_at']);
    }

    /**
     * Plenty of documents print only one of the two. With nothing to compare
     * against there is no contradiction, so the single date stands.
     */
    public function test_an_expiry_with_no_issue_date_is_kept(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'issued_at' => null,
            'expires_at' => '2027-03-01',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['issued_at']);
        $this->assertSame('2027-03-01', $fields['expires_at']);
    }

    /**
     * A TIN is issued for life, so an expiry read off one is the model
     * answering a question the card does not have.
     *
     * The prompt used to assert the opposite in as many words — it listed
     * 'an expiry date ("TIN ID ISSUE / EXPIRY DATE")' among the things an
     * authentic BIR card carries — so this was the system *instructing* the
     * hallucination. Kept, the date would put a permanent number into
     * `CredentialExpiryScanner`'s renewal queue to be chased forever.
     */
    public function test_a_tin_id_is_never_given_an_expiry(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'title' => 'Republic of the Philippines Department of Finance BUREAU OF INTERNAL REVENUE',
            'document_number' => '123-456-789',
            'issued_at' => '2024-03-01',
            // What the model actually does with a control number or an issue
            // date once it believes the card must carry an expiry.
            'expires_at' => '2029-03-01',
        ])->scan(UploadedFile::fake()->image('tin.jpg'));

        $this->assertNull($fields['expires_at']);
        $this->assertTrue($fields['never_expires']);

        // The rest of the reading is untouched: this clears one field, it does
        // not reject the document or doubt the type.
        $this->assertSame('government_id', $fields['type']);
        $this->assertSame('2024-03-01', $fields['issued_at']);
        // Kept as printed: `documentNumber()` strips the caption a scan puts
        // in front of a number, not the punctuation inside it.
        $this->assertSame('123-456-789', $fields['document_number']);
    }

    /**
     * **The assertion this rule exists to stay safe for.** A passport is a
     * `government_id` too, and it expires — so the fact could not be stated
     * per type, and `'government_id' => ['expires_at']` would have thrown away
     * a correctly read passport expiry to catch a TIN ID's invented one. That
     * is the expensive direction: an expiry silently dropped is a credential
     * that never reaches a renewal queue, which is invisible rather than
     * wrong.
     */
    public function test_a_passport_keeps_its_expiry(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'title' => 'Republic of the Philippines Department of Foreign Affairs PASSPORT',
            'issued_at' => '2023-08-14',
            'expires_at' => '2033-08-13',
        ])->scan(UploadedFile::fake()->image('passport.jpg'));

        $this->assertSame('2033-08-13', $fields['expires_at']);
        $this->assertFalse($fields['never_expires']);
    }

    /**
     * A clearance names an issuing agency in its letterhead and prints a real
     * "VALID UNTIL" date, so the card list must not be read against it. The
     * scoping is the config's own key rather than a type named in the code.
     */
    public function test_a_clearance_is_not_swept_up_by_the_card_list(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'title' => 'Republic of the Philippines Department of Justice NATIONAL BUREAU OF INVESTIGATION',
            'expires_at' => '2027-01-31',
        ])->scan(UploadedFile::fake()->image('nbi.jpg'));

        $this->assertSame('2027-01-31', $fields['expires_at']);
        $this->assertFalse($fields['never_expires']);
    }

    /**
     * One flag, both grains. The panel reads `never_expires` and nothing else,
     * so a type that never expires as a class has to answer through the same
     * field a single card does — otherwise the component is back to knowing
     * two rules, which is where a TIN ID fell through the first time.
     */
    public function test_a_type_that_never_expires_answers_through_the_same_flag(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'psa',
            'title' => 'Philippine Statistics Authority Certificate of Live Birth',
            'expires_at' => '2030-01-01',
        ])->scan(UploadedFile::fake()->image('psa.jpg'));

        $this->assertNull($fields['expires_at']);
        $this->assertTrue($fields['never_expires']);
    }

    /**
     * The prompt tells the model a null note is fine; the model sometimes
     * answers with that sentence, and it arrives looking like a finding.
     */
    public function test_the_prompt_echoed_back_as_a_note_is_dropped(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'contract',
            'note' => 'null unless something is genuinely worth flagging',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['note']);
    }

    public function test_a_real_note_survives(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'contract',
            'note' => 'The expiry date is already past.',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('The expiry date is already past.', $fields['note']);
    }

    /** Nothing better to offer when the type was rejected. */
    public function test_an_unrecognised_type_falls_back_to_the_models_title(): void
    {
        // A heading with no keyword in it either, so there is genuinely
        // nothing left to recover the type from.
        $fields = $this->scannerReturning([
            'type' => 'birth_certificate',
            'title' => 'Republic of the Philippines',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['type']);
        $this->assertSame('Republic of the Philippines', $fields['title']);
    }

    /**
     * These land in single-line inputs. A newline pasted into one silently
     * becomes a space, so a block of OCR output arrives looking deliberate.
     */
    public function test_free_text_is_flattened_to_a_single_line_and_capped(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'name_on_document' => "DELA CRUZ,\n   JUAN\t SANTOS",
            'note' => str_repeat('very long ', 40),
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('DELA CRUZ, JUAN SANTOS', $fields['name_on_document']);
        $this->assertStringNotContainsString("\n", $fields['note']);
        // The note gets a longer cap than the other fields — it is a sentence
        // about the document, not a value copied off it.
        $this->assertLessThanOrEqual(201, mb_strlen($fields['note']));
    }

    /** A DOCX upload skips the scanner; a PDF is now accepted. */
    public function test_a_non_image_is_not_scanned(): void
    {
        $scanner = app(DocumentScanner::class);

        // DOCX is still refused — only images and PDFs are accepted.
        $this->assertFalse($scanner->canScan(UploadedFile::fake()->create('contract.docx', 200)));
        // PDF is now accepted.
        $this->assertTrue($scanner->canScan(UploadedFile::fake()->create('contract.pdf', 200)));
        $this->assertTrue($scanner->canScan(UploadedFile::fake()->image('licence.jpg')));
    }

    public function test_an_oversized_image_is_refused_before_it_costs_a_request(): void
    {
        config(['scanner.max_bytes' => 1024]);

        $this->assertFalse(
            app(DocumentScanner::class)->canScan(UploadedFile::fake()->image('huge.jpg')->size(50)),
        );
    }

    // --- Reading a licence ------------------------------------------------

    public function test_it_fills_the_fields_from_a_drivers_licence(): void
    {
        $employee = $this->employee('Jane', 'Doe');

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => "Non-Professional Driver's Licence",
            'document_number' => 'A01-23-456789',
            'issued_at' => '2023-03-15',
            'expires_at' => '2027-03-15',
            'name_on_document' => 'DOE, JANE',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame('A01-23-456789', $fields['document_number']);
        $this->assertSame('2027-03-15', $fields['expires_at']);
        $this->assertTrue($fields['name_matches']);
    }

    /** NBI clearances print "VALID UNTIL" rather than an expiry label. */
    public function test_it_reads_an_nbi_clearance_valid_until_date(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'title' => 'NBI Clearance',
            'document_number' => 'M322CJYE79-L13108748',
            'issued_at' => '2024-07-31',
            'expires_at' => '2025-07-31',
            'name_on_document' => 'MADIGAS, JANE GAWILAN',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('nbi.jpg'), $this->employee('Jane', 'Madigas'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame('2025-07-31', $fields['expires_at']);
        $this->assertTrue($fields['name_matches']);
    }

    // --- Guarding against a bad reading -----------------------------------

    /** A type outside EmployeeDocument::TYPES never reaches the form. */
    public function test_an_unrecognised_document_type_is_dropped(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'birth_certificate',
            'title' => 'Something',
            'document_number' => null,
            'issued_at' => null,
            'expires_at' => null,
            'name_on_document' => null,
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['type']);
    }

    public function test_an_unparseable_date_becomes_null_rather_than_crashing(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'medical',
            'title' => 'Medical Certificate',
            'document_number' => null,
            'issued_at' => 'YYYY/MM/DD',
            'expires_at' => 'not a date',
            'name_on_document' => null,
            'confidence' => 'low',
            'note' => 'Sample document.',
        ])->scan(UploadedFile::fake()->image('sample.jpg'));

        $this->assertNull($fields['issued_at']);
        $this->assertNull($fields['expires_at']);
        $this->assertSame('low', $fields['confidence']);
    }

    /** The check that catches filing a document under the wrong person. */
    public function test_a_name_that_does_not_match_the_employee_is_flagged(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'title' => 'PhilSys National ID',
            'document_number' => '1234-5678-9101-1213',
            'issued_at' => '2019-06-14',
            'expires_at' => null,
            'name_on_document' => 'DELA CRUZ, JUANA MARTINEZ',
            'confidence' => 'high',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('id.jpg'), $this->employee('Pedro', 'Santos'));

        $this->assertFalse($fields['name_matches']);
        $this->assertSame('DELA CRUZ, JUANA MARTINEZ', $fields['name_on_document']);
    }

    /**
     * The name check has to survive a real ID, and the first version did not.
     *
     * A licence printed "JOHN GAVE" without the surname, the model read it as
     * "JONN GAVE", and the old test — first name *and* last name, both
     * verbatim — failed twice over: once to a missing word, once to a single
     * misread letter. Both are ordinary. Philippine IDs truncate long names
     * and OCR confuses H with N.
     *
     * @dataProvider nameComparisons
     */
    public function test_the_name_check_tolerates_how_ids_are_actually_printed(
        string $printed,
        string $first,
        string $middle,
        string $last,
        bool $expected,
        string $because,
    ): void {
        $employee = Employee::factory()->create([
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'name_on_document' => $printed,
        ])->scan(UploadedFile::fake()->image('id.jpg'), $employee);

        $this->assertSame($expected, $fields['name_matches'], $because);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: string}> */
    public static function nameComparisons(): array
    {
        return [
            // The case that prompted all of this.
            'surname missing and a letter misread' => [
                'JONN GAVE', 'John', 'Gave P.', 'Benavidez', true,
                'A truncated, slightly misread reading is still this person.',
            ],
            'full name, exactly' => [
                'JOHN GAVE P. BENAVIDEZ', 'John', 'Gave P.', 'Benavidez', true,
                'The straightforward case must not have regressed.',
            ],
            'surname printed first' => [
                'BENAVIDEZ, JOHN GAVE', 'John', 'Gave P.', 'Benavidez', true,
                'Order is not evidence — half of Philippine IDs lead with the surname.',
            ],
            'two-word surname' => [
                'DELA CRUZ, JUAN SANTOS', 'Juan', 'Santos', 'Dela Cruz', true,
                'A surname with a space is one name, not two mismatches.',
            ],
            'married name adds words' => [
                'MARIA SANTOS DELA CRUZ', 'Maria', '', 'Santos', true,
                'Every word of her name is there; the document simply has more.',
            ],
            'middle name absent from the document' => [
                'REYES, ANTONIO', 'Antonio', 'Cruz', 'Reyes', true,
                'Plenty of IDs omit the middle name.',
            ],

            // The cases the check exists to catch.
            'a different person entirely' => [
                'MARIE JUMIO', 'Anastasia', 'C.', 'Zemlak', false,
                'Nothing in common — this is the filing error being prevented.',
            ],
            'same given name, different surname' => [
                'ANTONIO SANTOS', 'Antonio', '', 'Reyes', false,
                'Given names repeat constantly; one match is not enough.',
            ],
            'a short given name one letter apart' => [
                'ANA REYES', 'Anna', '', 'Reyes', false,
                'Ana and Anna are different people. Fuzzy matching stops at short words, '
                .'or "Ana" would also match "Ann" and "Any".',
            ],
        ];
    }

    // --- The ID number, which outranks the name ----------------------------

    /**
     * The check that answers "is this really their ID" as honestly as it can
     * be answered here.
     *
     * Whether a card is *authentic* cannot be told from a photograph — that
     * needs the issuing agency, and there is no public LTO or PSA lookup. But
     * whether it is *theirs* can be, and a number already on the 201 file
     * answers it far better than a name: a licence number identifies one
     * person, and OCR reads digits more reliably than letters.
     */
    public function test_a_matching_licence_number_confirms_the_document(): void
    {
        $employee = Employee::factory()->create([
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertTrue($fields['number_matches']);
    }

    /**
     * The caption is read along with the value, and must not refuse the card.
     *
     * From a real scan: an NBI clearance came back with document_number
     * "NBI ID NO.: N2G4-25-123456" rather than the number alone. Compared for
     * equality that reads as a contradiction — and a contradicted number
     * *blocks the upload*, so the correct document, correctly read, was
     * refused because of the words printed next to the number.
     *
     * @dataProvider capturedNumbers
     */
    public function test_a_caption_read_with_the_number_does_not_refuse_the_card(
        string $printed,
        bool $expected,
    ): void {
        $employee = Employee::factory()->create([
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => $printed,
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertSame($expected, $fields['number_matches'], "\"{$printed}\"");
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function capturedNumbers(): array
    {
        return [
            'the number alone' => ['N01-23-456789', true],
            'captioned with a colon' => ['LICENSE NO.: N01-23-456789', true],
            'captioned without one' => ['License No N01-23-456789', true],

            // Still has to refuse someone else's card, caption or not.
            'a different licence' => ['N01-23-999999', false],
            'a different licence, captioned' => ['LICENSE NO.: N01-23-999999', false],
        ];
    }

    /**
     * A line that is not a number at all must become "unknown", not evidence.
     *
     * A real scan returned document_number "012 A-345, SAMPLE STREET, MANILA" —
     * the holder's address. Kept, it can never match the 201 file, and a
     * *contradicted* number blocks the upload: the employee's own ID refused
     * because the model read the wrong line. Dropped, the answer is null,
     * nothing is blocked, and the name check decides.
     *
     * @dataProvider implausibleNumbers
     */
    public function test_a_line_that_is_not_a_number_is_dropped(string $printed): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'document_number' => $printed,
        ])->scan(UploadedFile::fake()->image('id.jpg'));

        $this->assertNull($fields['document_number'], "\"{$printed}\" was kept.");
    }

    /** @return array<string, array{0: string}> */
    public static function implausibleNumbers(): array
    {
        return [
            'an address' => ['012 A-345, SAMPLE STREET, MANILA'],
            'a heading' => ['REPUBLIC OF THE PHILIPPINES'],
            'one word' => ['MANILA'],
            'a name' => ['DELA CRUZ, JUAN P.'],
        ];
    }

    /**
     * The formats actually carried in a 201 file, none of which may be lost to
     * the check above.
     *
     * @dataProvider realNumbers
     */
    public function test_real_id_numbers_survive_the_check(string $printed): void
    {
        $fields = $this->scannerReturning([
            'type' => 'government_id',
            'document_number' => $printed,
        ])->scan(UploadedFile::fake()->image('id.jpg'));

        $this->assertSame($printed, $fields['document_number']);
    }

    /** @return array<string, array{0: string}> */
    public static function realNumbers(): array
    {
        return [
            'LTO licence' => ['N01-23-456789'],
            'PhilSys' => ['1234-5678-9012-3456'],
            'UMID CRN' => ['CRN-0111-2222222-3'],
            'SSS' => ['34-1234567-8'],
            'PhilHealth' => ['12-345678901-2'],
            'TIN' => ['123-456-789-000'],
            'passport' => ['P1234567A'],
        ];
    }

    /**
     * And the form shows the number, not the line it was printed on — HR reads
     * this field to check the scan, so it should hold the value.
     */
    public function test_the_caption_is_stripped_from_what_the_form_shows(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'document_number' => 'NBI ID NO.: N2G4-25-123456',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('N2G4-25-123456', $fields['document_number']);
    }

    /** Punctuation is how it was typed, not part of the number. */
    public function test_the_number_is_compared_without_its_punctuation(): void
    {
        $employee = Employee::factory()->create([
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'n01 23 456789',
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertTrue($fields['number_matches']);
    }

    /**
     * `government_id` covers PhilSys, SSS, PhilHealth, Pag-IBIG and the TIN,
     * and the document does not say which — so any recorded number counts.
     */
    public function test_a_government_id_matches_any_recorded_number(): void
    {
        $employee = Employee::factory()->create([
            'sss_number' => '34-1234567-8',
            'tin' => '123-456-789-000',
        ]);

        foreach (['34-1234567-8', '123456789000'] as $printed) {
            $fields = $this->scannerReturning([
                'type' => 'government_id',
                'document_number' => $printed,
            ])->scan(UploadedFile::fake()->image('id.jpg'), $employee);

            $this->assertTrue($fields['number_matches'], "{$printed} should have matched.");
        }
    }

    /** The strongest evidence there is that a document is someone else's. */
    public function test_a_contradicting_number_is_reported(): void
    {
        $employee = Employee::factory()->create([
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'D99-88-777666',
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertFalse($fields['number_matches']);
    }

    /**
     * The one check that reads the document rather than who it belongs to.
     *
     * It matters most on the *first* document scanned for someone: there is
     * nothing on file to compare a number against, so without this the name —
     * the least reliable reading of the three — is the only evidence there is.
     */
    public function test_a_number_shaped_wrongly_for_its_type_is_flagged(): void
    {
        $employee = Employee::factory()->create();

        $wrong = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'ABC123',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertFalse($wrong['number_format_ok']);

        $right = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertTrue($right['number_format_ok']);
    }

    /** No pattern configured for the type is not a finding either. */
    public function test_a_type_with_no_known_format_reports_null(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'medical',
            'document_number' => 'MC-2026-4471',
        ])->scan(UploadedFile::fake()->image('x.jpg'), Employee::factory()->create());

        $this->assertNull($fields['number_format_ok']);
    }

    /** Nothing on file to compare against is not a finding. */
    public function test_no_recorded_number_reports_null(): void
    {
        $employee = Employee::factory()->create(['drivers_license_number' => null]);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('licence.jpg'), $employee);

        $this->assertNull($fields['number_matches']);
    }

    /** Nothing to compare against is not the same as a mismatch. */
    public function test_a_missing_name_reports_null_not_false(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'certificate',
            'title' => 'Training Certificate',
            'document_number' => null,
            'issued_at' => null,
            'expires_at' => null,
            'name_on_document' => null,
            'confidence' => 'medium',
            'note' => null,
        ])->scan(UploadedFile::fake()->image('cert.jpg'), $this->employee('Jane', 'Doe'));

        $this->assertNull($fields['name_matches']);
    }

    public function test_a_failed_call_returns_null_so_the_upload_still_works(): void
    {
        $fields = $this->scannerReturning(null)->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields);
    }

    // --- The endpoint ------------------------------------------------------

    public function test_the_endpoint_404s_when_the_scanner_is_off(): void
    {
        config(['scanner.api_key' => null]);

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$this->employee()->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertNotFound();
    }

    /** Same gate as the upload it precedes. */
    public function test_an_employee_cannot_scan_a_document(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/hr/employees/{$this->employee()->id}/documents/scan", [
                'file' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertForbidden();
    }

    public function test_the_show_page_hides_the_scanner_when_it_is_off(): void
    {
        config(['scanner.api_key' => null]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$this->employee()->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanDocuments', false));
    }

    public function test_the_show_page_offers_the_scanner_to_hr_when_it_is_on(): void
    {
        $this->actingAs($this->hr())
            ->get("/hr/employees/{$this->employee()->id}")
            ->assertInertia(fn ($page) => $page->where('can.scanDocuments', true));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // These cover the rules *around* read() — the driver underneath is
        // irrelevant to them, so one is pinned rather than left to whatever
        // .env happens to say.
        config([
            'scanner.driver' => 'anthropic',
            'scanner.api_key' => 'test-key',
        ]);
    }

    // --- Helpers -----------------------------------------------------------

    /** A scanner whose one API-touching method is replaced by a fixed answer. */
    private function scannerReturning(?array $reading): DocumentScanner
    {
        return new class($reading) extends DocumentScanner
        {
            public function __construct(private readonly ?array $reading)
            {
                parent::__construct();
            }

            protected function read(UploadedFile $file): ?array
            {
                return $this->reading;
            }
        };
    }

    private function employee(string $first = 'Juan', string $last = 'Dela Cruz'): Employee
    {
        return Employee::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'department_id' => Department::firstOrCreate(
                ['code' => 'OPS'],
                ['name' => 'Operations'],
            )->id,
        ]);
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
