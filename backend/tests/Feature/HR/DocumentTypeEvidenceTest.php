<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\User;
use App\Services\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * What the scanner uses to decide *what a document is*.
 *
 * It used to be two things: a keyword in the printed heading, and the model's
 * own choice of key when there was no keyword. Three more signals were already
 * sitting in data the scanner returns and were going unread — a number already
 * on the employee's 201 file, the shape of that number, and how long the
 * document is valid for.
 *
 * The order is the design, and it is the same precedence the identity check
 * uses: evidence a human already filed, then evidence printed on the paper,
 * then inference, then the model's guess last.
 */
class DocumentTypeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** A heading with no keyword in it, so only the signal under test fires. */
    private const NEUTRAL = 'LAST NAME, FIRST NAME, MIDDLE NAME';

    // --- 1. A number a person already filed ---------------------------------

    /**
     * The strongest signal available, and the only one that is not the model
     * reading the paper: HR typed this number into the licence field, so a
     * document carrying it is a licence.
     */
    public function test_a_number_on_the_employees_file_names_the_type(): void
    {
        $employee = Employee::factory()->create(['drivers_license_number' => 'N01-23-456789']);

        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'title' => self::NEUTRAL,
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_STORED_NUMBER, $fields['type_source']);
        $this->assertTrue($fields['type_certain']);
    }

    public function test_a_government_number_on_file_names_the_type(): void
    {
        $employee = Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $fields = $this->scannerReturning([
            'type' => 'certificate',
            'title' => self::NEUTRAL,
            'document_number' => 'SSS NO.: 34-1234567-8',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertSame('government_id', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_STORED_NUMBER, $fields['type_source']);
    }

    /**
     * The TIN on file matches what is printed on the TIN ID card. The scanner
     * recognises the card as government_id from the stored number alone —
     * before needing to read the BIR seal or any heading.
     */
    public function test_a_tin_on_file_names_the_type(): void
    {
        $employee = Employee::factory()->create(['tin' => '803-549-590']);

        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => 'Republic of the Philippines Department of Finance BUREAU OF INTERNAL REVENUE',
            'document_number' => '803-549-590',
            'issued_at' => null,
            'expires_at' => '2026-05-18',
        ])->scan(UploadedFile::fake()->image('tin.jpg'), $employee);

        $this->assertSame('government_id', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_STORED_NUMBER, $fields['type_source']);
        $this->assertTrue($fields['type_certain']);
    }

    /** The heading is still evidence from the paper, and outranks inference. */
    public function test_the_heading_still_beats_the_weaker_signals(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => 'NBI CLEARANCE',
            // Would otherwise resolve to a licence on its shape alone.
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_HEADING, $fields['type_source']);
    }

    // --- 3. The shape of the number ------------------------------------------

    public function test_an_lto_shaped_number_names_a_licence(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => self::NEUTRAL,
            'document_number' => 'A12-34-567890',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_NUMBER_FORMAT, $fields['type_source']);
        $this->assertFalse($fields['type_certain'], 'A shape is not worth refusing an upload over.');
    }

    /**
     * The false positive that shaped the rule.
     *
     * "MC-2026-4471" is two letters and eight digits, which is exactly the
     * passport pattern in `number_formats` — a fair check on a document
     * already filed as a government ID, and a disaster as a classifier. The
     * type-defining list is deliberately shorter for this reason.
     */
    public function test_a_loose_shape_does_not_decide_the_type(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'medical',
            'title' => self::NEUTRAL,
            'document_number' => 'MC-2026-4471',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('medical', $fields['type'], 'A passport-shaped number swallowed a medical certificate.');
        $this->assertSame(DocumentScanner::TYPE_FROM_MODEL, $fields['type_source']);
    }

    // --- 4. How long it is valid for -----------------------------------------

    /**
     * Two dates the model transcribed separately, so the gap between them is
     * not something it can bend to fit a guess. Five years is a licence, and
     * nothing else in a 201 file runs that long.
     */
    public function test_a_five_year_validity_names_a_licence(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => self::NEUTRAL,
            'issued_at' => '2021-03-14',
            'expires_at' => '2026-03-14',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_VALIDITY, $fields['type_source']);
    }

    /**
     * And where the real documents overlap, the rule declines rather than
     * inventing certainty: a year fits both a clearance and a medical, so it
     * decides nothing and the model's guess stands.
     *
     * @dataProvider overlappingPeriods
     */
    public function test_an_overlapping_validity_decides_nothing(string $issued, string $expires): void
    {
        $fields = $this->scannerReturning([
            'type' => 'certificate',
            'title' => self::NEUTRAL,
            'issued_at' => $issued,
            'expires_at' => $expires,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('certificate', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_MODEL, $fields['type_source']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function overlappingPeriods(): array
    {
        return [
            'a year — clearance or medical' => ['2026-03-14', '2027-03-14'],
            'six months — either as well' => ['2026-03-14', '2026-09-14'],
        ];
    }

    // --- 5. What the document rules out --------------------------------------

    /**
     * The reading that prompted this: a government ID uploaded, no heading
     * keyword, no recognisable number shape, no dates — so every positive
     * signal declined and the model's guess stood. It said "resume", and the
     * form filled the type in and looked confident about it.
     *
     * A résumé does not carry an ID number. The reading argued with itself,
     * and the honest answer to that is that the type is unknown — a null the
     * form leaves alone, not a second guess.
     */
    public function test_a_guess_the_document_contradicts_is_discarded(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'resume',
            'title' => 'LORENZO BONTIGAO PIKIT PIKIT',
            'document_number' => 'P231GLB040-MG2718306',
        ])->scan(UploadedFile::fake()->image('id.jpg'));

        $this->assertNull($fields['type'], 'A résumé does not have an ID number.');
        $this->assertNull($fields['type_source']);
        $this->assertFalse($fields['type_certain']);
    }

    public function test_a_resume_with_nothing_to_contradict_it_survives(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'resume',
            'title' => 'LORENZO BONTIGAO PIKIT PIKIT',
        ])->scan(UploadedFile::fake()->image('cv.jpg'));

        $this->assertSame('resume', $fields['type']);
    }

    /** A civil registry document records a birth; it does not lapse. */
    public function test_a_psa_document_that_expires_is_discarded(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'psa',
            'title' => self::NEUTRAL,
            'issued_at' => '2026-01-01',
            'expires_at' => '2027-01-01',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['type']);
    }

    /**
     * And the rule stays off the types where the claim would not be absolute.
     * An employment contract has an end date; ruling it out for having one
     * would discard correct readings to catch incorrect ones.
     */
    public function test_a_contract_with_an_end_date_is_left_alone(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'contract',
            'title' => self::NEUTRAL,
            'issued_at' => '2026-01-01',
            'expires_at' => '2027-01-01',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('contract', $fields['type']);
    }

    /**
     * Only the model's guess is second-guessed this way. Evidence from the
     * document does not need an absence to confirm it — a licence whose expiry
     * the model failed to read is still a licence.
     */
    public function test_evidence_is_not_overruled_by_the_contradiction_check(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'resume',
            'title' => 'NBI CLEARANCE',
            'document_number' => 'N2G4-25-123456',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_HEADING, $fields['type_source']);
    }

    /**
     * The headings of the cards actually filed in a Philippine 201 file.
     *
     * @dataProvider governmentIdHeadings
     */
    public function test_a_government_id_is_recognised_from_its_issuer(string $heading): void
    {
        $fields = $this->scannerReturning([
            'type' => 'resume',
            'title' => $heading,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('government_id', $fields['type'], "\"{$heading}\" was not recognised.");
    }

    /** @return array<string, array{0: string}> */
    public static function governmentIdHeadings(): array
    {
        return [
            'PhilID, in Filipino' => ['PAMBANSANG PAGKAKAKILANLAN'],
            'PhilID, in English' => ['PHILIPPINE IDENTIFICATION CARD'],
            'SSS' => ['SOCIAL SECURITY SYSTEM'],
            'UMID' => ['UNIFIED MULTI-PURPOSE ID'],
            'PRC' => ['PROFESSIONAL REGULATION COMMISSION'],
        ];
    }
    // --- 6. The issuing authority ---------------------------------------------

    /**
     * The miss that made this necessary, kept verbatim.
     *
     * A real NBI clearance was filed as a Driver's Licence. Its letterhead
     * reads "REPUBLIC OF THE PHILIPPINES / Department of Justice / National
     * Bureau of Investigation" — the word "NBI" appears nowhere on it, and
     * "nbi" was the only clearance keyword that could have matched. The
     * shorthand is what people say; the agency writes itself out in full.
     */
    public function test_a_letterhead_that_spells_the_agency_out_is_recognised(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'REPUBLIC OF THE PHILIPPPINES Department of Justice National Bureau of Investigation',
            'document_number' => 'P231GLBO40-MQ2718306',
        ])->scan(UploadedFile::fake()->image('nbi.jpg'));

        $this->assertSame('clearance', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_HEADING, $fields['type_source']);
    }

    /**
     * Every issuer a Philippine 201 file actually carries.
     *
     * @dataProvider letterheads
     */
    public function test_the_issuing_authority_names_the_type(string $heading, string $expected): void
    {
        $fields = $this->scannerReturning([
            // Deliberately wrong, so only the heading can produce the answer.
            'type' => 'other',
            'title' => $heading,
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame($expected, $fields['type'], "\"{$heading}\" was misfiled.");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function letterheads(): array
    {
        return [
            'NBI' => ['Department of Justice National Bureau of Investigation', 'clearance'],
            'NBI Seal' => ['Republic of the Philippines NBI Seal National Bureau of Investigation', 'clearance'],
            'dry seal' => ['Official Dry Seal National Bureau of Investigation', 'clearance'],
            'PNP' => ['Philippine National Police Police Clearance', 'clearance'],
            'LTO' => ['Land Transportation Office', 'drivers_license'],
            'PSA' => ['PHILIPPINE STATISTICS AUTHORITY', 'psa'],
            'civil registrar' => ['Office of the Civil Registrar', 'psa'],
            'DOH' => ['Republic of the Philippines DEPARTMENT OF HEALTH', 'medical'],
            'SSS' => ['SOCIAL SECURITY SYSTEM', 'government_id'],
            'BIR' => ['BUREAU OF INTERNAL REVENUE', 'government_id'],
            'PRC' => ['PROFESSIONAL REGULATION COMMISSION', 'government_id'],
            'DFA passport' => ['DEPARTMENT OF FOREIGN AFFAIRS', 'government_id'],
            'BIR TIN ID' => ['Republic of the Philippines Department of Finance BUREAU OF INTERNAL REVENUE', 'government_id'],
            'TIN ID taxpayer label' => ['TAXPAYER IDENTIFICATION NUMBER BUREAU OF INTERNAL REVENUE', 'government_id'],
            'TIN ID card' => ['TIN ID Bureau of Internal Revenue', 'government_id'],
            'BIR seal on card' => ['BIR Seal Taxpayer Identification Number', 'government_id'],
            'Digital TIN ID' => ['Digital TIN ID Control Number Bureau of Internal Revenue', 'government_id'],

            // Still has to leave the types that are not agencies alone.
            'TESDA' => ['TESDA Certificate of Competency', 'certificate'],
        ];
    }

    /**
     * The reading that argued with itself.
     *
     * The same panel said "Drivers License" and, one line below, "…is not
     * shaped like a Drivers License number". Filling the field in anyway was
     * the system publishing a contradiction. It does not refuse the upload —
     * `number_format_ok` stays advisory — it just declines to fill a type in
     * from a guess the document disagrees with.
     */
    public function test_a_guess_the_number_shape_argues_with_is_discarded(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => self::NEUTRAL,
            'document_number' => 'P231GLBO40-MQ2718306',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['type']);
    }

    /** A licence number of the right shape is of course left alone. */
    public function test_a_guess_the_number_shape_supports_is_kept(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => self::NEUTRAL,
            'document_number' => 'N01-23-456789',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('drivers_license', $fields['type']);
    }
    // --- 7. Whether it has already lapsed -------------------------------------

    /**
     * The gap this closes: a licence two years out of date was filed, looked
     * fine on the panel, and only turned up on the Credentials screen later.
     * Nobody should be able to file an expired document without being told at
     * the moment they file it.
     */
    public function test_a_lapsed_document_is_reported_at_the_moment_it_is_filed(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'Land Transportation Office',
            'issued_at' => '2019-07-25',
            'expires_at' => '2024-07-25',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('expired', $fields['expiry']['state']);
        $this->assertLessThan(0, $fields['expiry']['days']);
        $this->assertTrue($fields['expiry']['blocking'], 'A driver may not work on a lapsed licence.');
    }

    /**
     * The renewal windows are per type and come from config/credentials.php,
     * not from a second opinion held here — this panel, the Credentials screen
     * and Deployment Readiness have to agree about the same licence.
     *
     * @dataProvider renewalWindows
     */
    public function test_the_renewal_window_is_the_one_credentials_uses(
        string $type,
        int $daysAhead,
        string $expected,
    ): void {
        $fields = $this->scannerReturning([
            'type' => $type,
            'title' => self::NEUTRAL,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addDays($daysAhead)->toDateString(),
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame($expected, $fields['expiry']['state']);
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function renewalWindows(): array
    {
        return [
            // A licence gets 60 days of lead time; a clearance 45.
            'licence, 50 days out' => ['drivers_license', 50, 'expiring'],
            'licence, 90 days out' => ['drivers_license', 90, 'valid'],
            'clearance, 50 days out' => ['clearance', 50, 'valid'],
            'clearance, 30 days out' => ['clearance', 30, 'expiring'],
        ];
    }

    /** No expiry date is not a finding — most documents do not have one. */
    public function test_a_document_with_no_expiry_reports_nothing(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'psa',
            'title' => 'PHILIPPINE STATISTICS AUTHORITY',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['expiry']);
    }

    /**
     * Reported, never refused. An expired document is filed deliberately often
     * enough — for the record, or while the renewal is in progress — that
     * blocking the upload would leave the 201 file emptier than the truth.
     * Deployment Readiness is the screen that stops somebody being sent out.
     */
    public function test_an_expired_document_is_still_allowed_to_be_filed(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->post("/hr/employees/{$employee->id}/documents", [
                'type' => 'drivers_license',
                'title' => "Driver's Licence",
                'issued_at' => '2019-07-25',
                'expires_at' => '2024-07-25',
                'file' => UploadedFile::fake()->image('licence.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_documents', ['employee_id' => $employee->id]);
    }
    // --- 8. Fields the type cannot have --------------------------------------

    /**
     * A PSA certificate identified from its letterhead is *correctly* typed,
     * and the model can still have read a date off it — PSA paper prints an
     * issue date and a registry date, and a misread of either arrives looking
     * like an expiry. Kept, it would put a birth certificate into
     * CredentialExpiryScanner's renewal queue and it would be chased forever
     * for a renewal that does not exist.
     */
    public function test_a_psa_document_is_never_given_an_expiry(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => 'PHILIPPINE STATISTICS AUTHORITY Certificate of Live Birth',
            'issued_at' => '2026-01-10',
            'expires_at' => '2027-01-10',
        ])->scan(UploadedFile::fake()->image('psa.jpg'));

        $this->assertSame('psa', $fields['type']);
        $this->assertNull($fields['expires_at']);
        $this->assertNull($fields['expiry']);
    }

    /** A résumé has neither an expiry nor an ID number. */
    public function test_a_resume_is_given_neither_an_expiry_nor_a_number(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'resume',
            'title' => 'CURRICULUM VITAE',
            'document_number' => 'AB1234567',
            'expires_at' => '2027-01-10',
        ])->scan(UploadedFile::fake()->image('cv.jpg'));

        $this->assertSame('resume', $fields['type']);
        $this->assertNull($fields['expires_at']);
        $this->assertNull($fields['document_number']);
    }

    /**
     * And the types that genuinely do carry a date keep it. A contract has an
     * end date; clearing it would lose real information to catch a fault that
     * is not there.
     *
     * @dataProvider typesThatExpire
     */
    public function test_a_type_that_really_expires_keeps_its_date(string $heading, string $expected): void
    {
        $fields = $this->scannerReturning([
            'type' => 'other',
            'title' => $heading,
            'issued_at' => '2026-01-01',
            'expires_at' => '2027-01-01',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame($expected, $fields['type']);
        $this->assertSame('2027-01-01', $fields['expires_at']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function typesThatExpire(): array
    {
        return [
            'licence' => ['Land Transportation Office', 'drivers_license'],
            'clearance' => ['Department of Justice National Bureau of Investigation', 'clearance'],
            'medical' => ['DEPARTMENT OF HEALTH fit to work', 'medical'],
        ];
    }

    /**
     * The screen is no longer handed a list of types to reason from, and that
     * is the fix rather than a regression.
     *
     * It used to receive `neverExpires` — the types from `type_cannot_have`
     * that carry no expiry — and the component matched `scan.type` against it.
     * That can only ever be right for a type that never expires **as a
     * class**, and the case that broke it is a TIN ID: it is a
     * `government_id`, exactly like a passport, so no list of types can
     * separate the one that is issued for life from the one whose expiry
     * matters. A TIN ID therefore showed "Not found" under Expires, which
     * reads as the scanner having looked and missed and invites HR to type a
     * date that does not exist.
     *
     * So the decision moved to the one place that has the evidence — the
     * heading printed on the card — and the panel reads a single
     * `never_expires` flag off the scan. The screen and the scanner cannot
     * disagree because there is no longer a second copy of the rule to
     * disagree with.
     */
    public function test_the_screen_is_not_given_a_list_of_types_to_judge_expiry_by(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->hrStaff()->create())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(fn ($page) => $page->missing('neverExpires'));
    }

    /**
     * Both grains answer through the same field, which is what lets the panel
     * hold one rule instead of two.
     *
     * A TIN ID is decided by the card (`non_expiring_ids`) and a PSA
     * certificate by its type (`type_cannot_have`); a passport is a
     * `government_id` that genuinely expires and must come back false, since
     * an expiry silently dropped is a credential that never reaches a renewal
     * queue — invisible rather than wrong.
     *
     * @dataProvider expiryByDocument
     */
    public function test_the_scan_says_whether_there_was_an_expiry_to_find(
        string $type,
        string $heading,
        bool $expected,
    ): void {
        $fields = $this->scannerReturning([
            'type' => $type,
            'title' => $heading,
            'expires_at' => '2030-06-30',
        ])->scan(UploadedFile::fake()->image('doc.jpg'));

        $this->assertSame($expected, $fields['never_expires']);

        // And the date itself follows the flag, in both directions.
        $this->assertSame($expected ? null : '2030-06-30', $fields['expires_at']);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function expiryByDocument(): array
    {
        return [
            'TIN ID — a TIN is issued for life' => [
                'government_id', 'BUREAU OF INTERNAL REVENUE', true,
            ],
            'UMID — the SSS common card' => [
                'government_id', 'Unified Multi-Purpose ID', true,
            ],
            'PhilID — no expiry for an adult' => [
                'government_id', 'Philippine Identification System', true,
            ],
            'passport — expires, and it matters' => [
                'government_id', 'Department of Foreign Affairs PASSPORT', false,
            ],
            'PSA — by type, not by card' => [
                'psa', 'Philippine Statistics Authority', true,
            ],
            'NBI clearance — prints a real VALID UNTIL' => [
                'clearance', 'NATIONAL BUREAU OF INVESTIGATION', false,
            ],
        ];
    }
    // --- The order ------------------------------------------------------------

    /**
     * Each signal beats every weaker one. Asserted as a chain rather than in
     * isolation, because the order is the part that would rot quietly: a
     * reordering leaves every individual rule still working.
     */
    public function test_the_signals_are_tried_strongest_first(): void
    {
        $employee = Employee::factory()->create(['drivers_license_number' => 'N01-23-456789']);

        // Everything at once, each pointing somewhere different.
        $fields = $this->scannerReturning([
            'type' => 'certificate',                 // model says certificate
            'title' => 'NBI CLEARANCE',              // heading says clearance
            'document_number' => 'N01-23-456789',    // on file as a licence
            'issued_at' => '2026-03-14',
            'expires_at' => '2027-03-14',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertSame('drivers_license', $fields['type']);
        $this->assertSame(DocumentScanner::TYPE_FROM_STORED_NUMBER, $fields['type_source']);
    }

    /** Only evidence from the document itself is worth refusing an upload over. */
    public function test_only_the_two_strongest_sources_are_certain(): void
    {
        $certain = [DocumentScanner::TYPE_FROM_STORED_NUMBER, DocumentScanner::TYPE_FROM_HEADING];

        foreach ([
            DocumentScanner::TYPE_FROM_NUMBER_FORMAT,
            DocumentScanner::TYPE_FROM_VALIDITY,
            DocumentScanner::TYPE_FROM_MODEL,
        ] as $source) {
            $this->assertNotContains($source, $certain, "{$source} must not block an upload.");
        }
    }

    private function scannerReturning(?array $reading): DocumentScanner
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

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
}
