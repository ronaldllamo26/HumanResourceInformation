<?php

namespace Tests\Feature\HR;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\RecordIntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the records disagree with each other.
 *
 * 201 File Status asks what is missing and Credentials asks what is lapsing.
 * Neither can see a number keyed against two people, a licence filed twice, or
 * a scanned document that named somebody the file does not.
 *
 * Every rule here is a regex or a string comparison, deliberately: a TIN with
 * eleven digits is wrong for a reason that can be written down, and a rule
 * that can be written down should not be inferred.
 */
class RecordIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // --- A clean file is not a finding -----------------------------------------

    public function test_records_that_agree_produce_nothing(): void
    {
        Employee::factory()->create([
            'sss_number' => '34-1234567-8',
            'philhealth_number' => '12-345678901-2',
            'pagibig_number' => '1234-5678-9012',
            'tin' => '123-456-789',
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $this->assertCount(0, $this->scan());
    }

    // --- Two people, one number --------------------------------------------------

    /**
     * The finding worth having most, and the one a spreadsheet import makes
     * likely. Left alone it puts one employee's contributions under another's
     * name at remittance time.
     */
    public function test_a_number_held_by_two_people_is_an_error_on_both(): void
    {
        Employee::factory()->create(['employee_number' => 'PPM-A', 'sss_number' => '34-1234567-8']);
        Employee::factory()->create(['employee_number' => 'PPM-B', 'sss_number' => '34-1234567-8']);

        $rows = $this->scan();

        $this->assertCount(2, $rows, 'Both records are wrong until somebody says which.');
        $this->assertSame('error', $rows[0]['severity']);
        $this->assertStringContainsString('PPM-B', $rows->firstWhere('employee_number', 'PPM-A')['findings'][0]['detail']);
    }

    /** Punctuation is how it was typed, not part of the number. */
    public function test_the_same_number_punctuated_differently_still_collides(): void
    {
        Employee::factory()->create(['sss_number' => '34-1234567-8']);
        Employee::factory()->create(['sss_number' => '3412345678']);

        $this->assertContains('duplicate_number', $this->types());
    }

    /**
     * Looked for across every record, not only the ones in scope. A collision
     * with somebody this user cannot see is still a collision, and hiding it
     * would let the duplicate survive because of who happened to be looking.
     */
    public function test_a_collision_outside_the_scope_is_still_reported(): void
    {
        $mine = Employee::factory()->create(['sss_number' => '34-1234567-8']);
        Employee::factory()->create(['sss_number' => '34-1234567-8']);

        $rows = app(RecordIntegrityChecker::class)->scan(Employee::whereKey($mine->id));

        $this->assertCount(1, $rows);
        $this->assertSame('duplicate_number', $rows[0]['findings'][0]['type']);
    }

    // --- Shapes ------------------------------------------------------------------

    /**
     * @dataProvider malformedNumbers
     */
    public function test_a_number_that_is_not_its_agencys_shape_is_reported(string $field, string $value): void
    {
        Employee::factory()->create([$field => $value]);

        $this->assertContains('number_format', $this->types(), "{$field} {$value} went unreported.");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedNumbers(): array
    {
        return [
            'SSS too short' => ['sss_number', '34-12345-8'],
            'PhilHealth too short' => ['philhealth_number', '12-3456789-2'],
            'TIN eleven digits' => ['tin', '123-456-789-0'],
            'licence with three letters' => ['drivers_license_number', 'ZVE-07-277953'],
        ];
    }

    /** A TIN carries nine digits, or twelve with the branch code. Both are right. */
    public function test_both_tin_lengths_are_accepted(): void
    {
        Employee::factory()->create(['tin' => '123-456-789']);
        Employee::factory()->create(['tin' => '123-456-789-000']);

        $this->assertNotContains('number_format', $this->types());
    }

    /**
     * Absent is OnboardingChecker's finding. Saying it twice on two screens
     * teaches people to ignore both.
     */
    public function test_a_missing_number_is_not_this_screens_finding(): void
    {
        Employee::factory()->create(['sss_number' => null, 'tin' => null]);

        $this->assertNotContains('number_format', $this->types());
    }

    // --- Documents ----------------------------------------------------------------

    public function test_two_current_copies_of_a_single_copy_document_are_reported(): void
    {
        $employee = Employee::factory()->create();

        foreach ([1, 2] as $i) {
            $this->document($employee, 'contract', expires: null);
        }

        $this->assertContains('duplicate_document', $this->types());
    }

    /**
     * A renewal with its history kept is not a duplicate — the old licence has
     * lapsed, and keeping it is correct.
     */
    public function test_a_superseded_copy_is_not_a_duplicate(): void
    {
        $employee = Employee::factory()->create();

        $this->document($employee, 'drivers_license', expires: now()->subYear()->toDateString());
        $this->document($employee, 'drivers_license', expires: now()->addYears(4)->toDateString());

        $this->assertNotContains('duplicate_document', $this->types());
    }

    /** Clearances and certificates accumulate legitimately, so they are not checked. */
    public function test_several_clearances_are_not_a_duplicate(): void
    {
        $employee = Employee::factory()->create();

        foreach ([1, 2, 3] as $i) {
            $this->document($employee, 'clearance', expires: now()->addYear()->toDateString());
        }

        $this->assertNotContains('duplicate_document', $this->types());
    }

    public function test_a_document_dated_before_the_employee_was_born_is_an_error(): void
    {
        $employee = Employee::factory()->create(['birth_date' => '1995-03-14']);

        $this->document($employee, 'clearance', issued: '1990-01-01');

        $findings = $this->findings();

        $this->assertContains('date_conflict', array_column($findings, 'type'));
        $this->assertSame('error', $findings[0]['severity']);
    }

    // --- What a scan said -----------------------------------------------------------

    /**
     * Reads what the scanner already recorded for the accuracy figures, so
     * this costs a query rather than a model call.
     */
    public function test_a_scanned_document_naming_somebody_else_is_reported(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        DocumentScan::create([
            'employee_id' => $employee->id,
            'driver' => 'gemini',
            'proposed' => ['type' => 'clearance', 'name_on_document' => 'RIZAL, JOSE PROTACIO'],
        ]);

        $this->assertContains('name_mismatch', $this->types());
    }

    /**
     * A birth certificate names the employee's child. Reporting that here
     * would repeat, on a second screen, a judgement the upload form already
     * decided was not a mismatch.
     */
    public function test_a_certificate_naming_a_dependant_is_not_reported(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Adelia', 'last_name' => 'Bontigao']);

        DocumentScan::create([
            'employee_id' => $employee->id,
            'driver' => 'gemini',
            'proposed' => ['type' => 'psa', 'name_on_document' => 'LORENZO BONTIGAO PIKIT PIKIT'],
        ]);

        $this->assertNotContains('name_mismatch', $this->types());
    }

    /** One finding per name, however many documents carried it. */
    public function test_the_same_wrong_name_on_two_scans_is_reported_once(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        foreach ([1, 2] as $i) {
            DocumentScan::create([
                'employee_id' => $employee->id,
                'driver' => 'gemini',
                'proposed' => ['type' => 'clearance', 'name_on_document' => 'RIZAL, JOSE PROTACIO'],
            ]);
        }

        $this->assertCount(1, array_filter($this->types(), fn ($t) => $t === 'name_mismatch'));
    }

    // --- The way in --------------------------------------------------------------------

    public function test_hr_can_open_the_screen(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/record-checks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HR/Analytics/RecordChecks'));
    }

    /** Scoped like the directory: a supervisor sees their own reports. */
    public function test_a_supervisor_sees_only_their_own_reports(): void
    {
        $supervisor = Employee::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_SUPERVISOR]);
        $supervisor->update(['user_id' => $user->id]);

        Employee::factory()->create([
            'supervisor_id' => $supervisor->id,
            'sss_number' => '34-12345-8',
        ]);
        Employee::factory()->create(['sss_number' => '99-99999-9']);

        $this->actingAs($user)
            ->get('/hr/record-checks')
            ->assertInertia(fn ($page) => $page->has('rows', 1));
    }

    private function scan()
    {
        return app(RecordIntegrityChecker::class)->scan(Employee::query());
    }

    private function findings(): array
    {
        return $this->scan()->flatMap(fn (array $row) => $row['findings'])->all();
    }

    private function types(): array
    {
        return array_column($this->findings(), 'type');
    }

    private function document(Employee $employee, string $type, ?string $expires = null, ?string $issued = null): EmployeeDocument
    {
        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => 'x',
            'issued_at' => $issued,
            'expires_at' => $expires,
            'file_path' => 'x.jpg',
            'file_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1,
        ]);
    }
}
