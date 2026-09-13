<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Services\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Reading a PSA certificate, and deciding whether it belongs on this file.
 *
 * Two problems, both measured on a real certificate. The general prompt asks
 * for an ID card's fields — a number, an issue date, an expiry — and a
 * Certificate of Live Birth has none of them, so the model answered with
 * whatever sat nearby: the receiving date became an expiry and the mother's
 * occupation became the note. And the name check refused the upload outright,
 * because a birth certificate names the employee's *child*.
 */
class CivilRegistryScanTest extends TestCase
{
    use RefreshDatabase;

    // --- Not refused for being what it is --------------------------------------

    /**
     * The certificate filed in a 201 file is usually a dependant's, kept for
     * BIR and PhilHealth claims. Refusing it for naming somebody else is
     * refusing it for being a birth certificate.
     */
    public function test_a_certificate_naming_a_dependant_is_not_a_mismatch(): void
    {
        $mother = Employee::factory()->create([
            'first_name' => 'Adelia',
            'last_name' => 'Bontigao',
        ]);

        $fields = $this->scannerReturning([
            'type' => 'psa',
            'title' => 'CERTIFICATE OF LIVE BIRTH',
            'name_on_document' => 'LORENZO BONTIGAO PIKIT PIKIT',
        ])->scan(UploadedFile::fake()->image('psa.jpg'), $mother);

        $this->assertFalse($fields['name_matches'], 'The check still runs and still reports.');
        $this->assertTrue($fields['name_may_differ'], 'It just does not refuse the upload.');
    }

    /** And every other type still refuses, because there the match is expected. */
    public function test_a_licence_naming_somebody_else_is_still_a_mismatch(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Adelia', 'last_name' => 'Bontigao']);

        $fields = $this->scannerReturning([
            'type' => 'drivers_license',
            'title' => 'Land Transportation Office',
            'name_on_document' => 'SOMEBODY ELSE',
        ])->scan(UploadedFile::fake()->image('x.jpg'), $employee);

        $this->assertFalse($fields['name_matches']);
        $this->assertFalse($fields['name_may_differ']);
    }

    // --- The second pass ---------------------------------------------------------

    public function test_a_certificate_is_read_in_its_own_terms(): void
    {
        $fields = $this->scannerReturning(
            ['type' => 'psa', 'title' => 'CERTIFICATE OF LIVE BIRTH'],
            registry: [
                'registry_no' => '2006-1229',
                'child_first_name' => 'LORENZO',
                'child_middle_name' => 'BONTIGAO',
                'child_last_name' => 'PIKIT PIKIT',
                'child_sex' => 'male',
                'child_birth_date' => '2006-07-02',
                'child_birth_place' => 'QUIRINO MEMORIAL MEDICAL CENTER, QC',
                'mother_first_name' => 'ADELIA',
                'mother_middle_name' => 'FORMENTO',
                'mother_maiden_last_name' => 'BONTIGAO',
                'father_first_name' => 'LEOPOLDO JR.',
                'father_middle_name' => 'ARONG',
                'father_last_name' => 'PIKIT PIKIT',
            ],
        )->scan(UploadedFile::fake()->image('psa.jpg'));

        $registry = $fields['registry'];

        $this->assertSame('2006-1229', $registry['registry_no']);
        $this->assertSame('LORENZO BONTIGAO PIKIT PIKIT', $registry['child']);
        $this->assertSame('2006-07-02', $registry['birth_date']);
        $this->assertSame('ADELIA FORMENTO BONTIGAO', $registry['mother']);
        $this->assertSame('LEOPOLDO JR. ARONG PIKIT PIKIT', $registry['father']);
        $this->assertFalse($registry['parents_uncertain']);
    }

    /** The second pass runs for a certificate and for nothing else. */
    public function test_no_second_pass_for_other_documents(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'clearance',
            'title' => 'NBI CLEARANCE',
        ])->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertNull($fields['registry']);
    }

    // --- The claim ----------------------------------------------------------------

    /**
     * The question worth asking about a birth certificate is not whose name is
     * on it — that is the child — but whether this employee is a parent on it.
     */
    public function test_a_parent_named_on_the_certificate_is_recognised(): void
    {
        $mother = Employee::factory()->create([
            'first_name' => 'Adelia',
            'middle_name' => 'Formento',
            'last_name' => 'Bontigao',
        ]);

        $fields = $this->scannerReturning(
            ['type' => 'psa', 'title' => 'CERTIFICATE OF LIVE BIRTH'],
            registry: [
                'mother_first_name' => 'ADELIA',
                'mother_middle_name' => 'FORMENTO',
                'mother_maiden_last_name' => 'BONTIGAO',
                'father_first_name' => 'LEOPOLDO JR.',
                'father_middle_name' => 'ARONG',
                'father_last_name' => 'PIKIT PIKIT',
            ],
        )->scan(UploadedFile::fake()->image('psa.jpg'), $mother);

        $this->assertTrue($fields['registry']['claimed_by_employee']);
    }

    public function test_an_employee_who_is_no_parent_here_is_reported(): void
    {
        $stranger = Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Reyes']);

        $fields = $this->scannerReturning(
            ['type' => 'psa', 'title' => 'CERTIFICATE OF LIVE BIRTH'],
            registry: [
                'mother_first_name' => 'ADELIA',
                'mother_middle_name' => 'FORMENTO',
                'mother_maiden_last_name' => 'BONTIGAO',
            ],
        )->scan(UploadedFile::fake()->image('psa.jpg'), $stranger);

        $this->assertFalse($fields['registry']['claimed_by_employee']);
    }

    // --- The collision -------------------------------------------------------------

    /**
     * The measured failure, verbatim.
     *
     * On a certificate whose father is "Leopoldo Jr. Arong Pikit Pikit" and
     * mother "Adelia Formento Bontigao", the model returned the mother as
     * "Leopoldo Jr. Arong Bontigao" — her surname from the right box, his
     * given names bled in from item 13.
     *
     * Two parents do not share a first *and* a middle name. Which box was
     * misread cannot be told from the values, so neither is trusted and the
     * claim is left unanswered rather than answered wrongly.
     */
    public function test_two_parents_sharing_given_names_is_a_misread(): void
    {
        $mother = Employee::factory()->create([
            'first_name' => 'Adelia',
            'middle_name' => 'Formento',
            'last_name' => 'Bontigao',
        ]);

        $fields = $this->scannerReturning(
            ['type' => 'psa', 'title' => 'CERTIFICATE OF LIVE BIRTH'],
            registry: [
                'mother_first_name' => 'LEOPOLDO JR.',
                'mother_middle_name' => 'ARONG',
                'mother_maiden_last_name' => 'BONTIGAO',
                'father_first_name' => 'LEOPOLDO JR.',
                'father_middle_name' => 'ARONG',
                'father_last_name' => 'PIKIT PIKIT',
            ],
        )->scan(UploadedFile::fake()->image('psa.jpg'), $mother);

        $this->assertTrue($fields['registry']['parents_uncertain']);
        $this->assertNull(
            $fields['registry']['claimed_by_employee'],
            'An unanswerable question must not be answered.',
        );
    }

    /** A shared surname is ordinary and must not fire the check. */
    public function test_parents_sharing_only_a_surname_are_not_a_misread(): void
    {
        $fields = $this->scannerReturning(
            ['type' => 'psa', 'title' => 'CERTIFICATE OF LIVE BIRTH'],
            registry: [
                'mother_first_name' => 'MARIA',
                'mother_maiden_last_name' => 'SANTOS',
                'father_first_name' => 'JUAN',
                'father_last_name' => 'SANTOS',
            ],
        )->scan(UploadedFile::fake()->image('psa.jpg'));

        $this->assertFalse($fields['registry']['parents_uncertain']);
    }

    /** A certificate is still never given an expiry, whatever the pass returns. */
    public function test_a_certificate_is_still_never_given_an_expiry(): void
    {
        $fields = $this->scannerReturning([
            'type' => 'psa',
            'title' => 'CERTIFICATE OF LIVE BIRTH',
            'issued_at' => '2006-07-10',
            'expires_at' => '2007-07-10',
        ])->scan(UploadedFile::fake()->image('psa.jpg'));

        $this->assertNull($fields['expires_at']);
        $this->assertNull($fields['expiry']);
    }

    private function scannerReturning(array $reading, array $registry = []): DocumentScanner
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        return new class($reading, $registry) extends DocumentScanner
        {
            public function __construct(
                private readonly array $reading,
                private readonly array $registry,
            ) {
                parent::__construct();
            }

            protected function read(UploadedFile $file): ?array
            {
                return $this->reading;
            }

            /*
             * Only the *call* is stubbed. The joining and the parent
             * collision rule below it are the real ones, which is the
             * point — those are what these tests exercise.
             */
            protected function readCivilRegistry(?UploadedFile $file): ?array
            {
                return $this->registry;
            }
        };
    }
}
