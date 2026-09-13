<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\DataAccessLogger;
use App\Services\EmployeeService;
use App\Services\RecordIntegrityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The layer underneath the policies.
 *
 * Every access gate in Modules 1, 2, and 4 was already correct — an employee
 * cannot open someone else's 201 file, approve a payroll run, or record
 * attendance. What was missing was what happens *after* a correctly authorised
 * request: an authorised token had no ceiling, and reading or exporting
 * personal data left no trace. These cover both.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // --- The API had no ceiling --------------------------------------------

    /**
     * Every endpoint was gated; none was paced. The API serves the employee
     * directory and 201-file documents, so an authorised token could walk the
     * whole workforce as fast as the server answered — and these are
     * unattended credentials sitting on biometric devices.
     */
    public function test_the_api_throttles_a_token_that_pulls_too_fast(): void
    {
        config(['sanctum.rate_limit' => 5]);

        $token = User::factory()->hrStaff()->create()->createToken('device')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->getJson('/api/v1/employees')->assertOk();
        }

        $this->withHeaders($headers)
            ->getJson('/api/v1/employees')
            ->assertStatus(429);
    }

    /**
     * Keyed by token, not by account: two devices on one service account must
     * not throttle each other, and one copied token must not inherit the
     * whole account's budget.
     */
    public function test_one_exhausted_token_does_not_block_another(): void
    {
        config(['sanctum.rate_limit' => 3]);

        $user = User::factory()->hrStaff()->create();
        $first = $user->createToken('device-a')->plainTextToken;
        $second = $user->createToken('device-b')->plainTextToken;

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders(['Authorization' => "Bearer {$first}"])
                ->getJson('/api/v1/employees');
        }

        $this->withHeaders(['Authorization' => "Bearer {$first}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/employees')
            ->assertStatus(429);

        $this->withHeaders(['Authorization' => "Bearer {$second}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/employees')
            ->assertOk();
    }

    // --- Reading personal data now leaves a trace --------------------------

    /**
     * `Auditable` answers "who changed this record" and the auth listener
     * answers "who signed in". Neither answered "who opened this person's
     * PhilSys ID" — which for an HRIS is the question with teeth.
     */
    public function test_downloading_a_document_is_recorded(): void
    {
        [$hr, $employee, $document] = $this->uploadedDocument();

        $this->actingAs($hr)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertOk();

        $entry = AuditLog::where('event', DataAccessLogger::EVENT_ACCESSED)->latest('id')->first();

        $this->assertNotNull($entry, 'The download left no trace.');
        $this->assertSame($hr->id, $entry->user_id);
        $this->assertSame($document->id, $entry->auditable_id);
        $this->assertSame('download', $entry->new_values['how']);
    }

    /** Reading an ID on screen and taking a copy away are different acts. */
    public function test_previewing_is_recorded_separately_from_downloading(): void
    {
        [$hr, $employee, $document] = $this->uploadedDocument();

        $this->actingAs($hr)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/preview")
            ->assertOk();

        $this->assertSame(
            'preview',
            AuditLog::where('event', DataAccessLogger::EVENT_ACCESSED)->latest('id')->first()->new_values['how'],
        );
    }

    /**
     * The log records access, not attempts. A refused request never reached
     * the data, and recording it as an access would make the log lie in the
     * direction that matters most.
     */
    public function test_a_refused_download_is_not_recorded_as_an_access(): void
    {
        [, $employee, $document] = $this->uploadedDocument();

        $outsider = User::factory()->create();
        Employee::factory()->create(['user_id' => $outsider->id]);

        $this->actingAs($outsider)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertForbidden();

        $this->assertSame(
            0,
            AuditLog::where('event', DataAccessLogger::EVENT_ACCESSED)->count(),
        );
    }

    // --- Bulk extracts leave a trace too -----------------------------------

    /**
     * The broadest extract there is. Without a row here, a full copy of the
     * workforce's contact details leaves the system silently.
     */
    public function test_exporting_the_employee_directory_is_recorded(): void
    {
        Employee::factory()->count(3)->create();

        $this->actingAs(User::factory()->role(User::ROLE_ADMIN)->create())
            ->get('/settings/data/export/employees')
            ->assertOk();

        $entry = AuditLog::where('event', DataAccessLogger::EVENT_EXPORTED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('employee-directory', $entry->new_values['report']);
        $this->assertSame(3, $entry->new_values['employees']);
    }

    /**
     * An export is about many rows, so it points at no single id — the same
     * split a failed sign-in uses, and for the same reason. The *kind* of
     * record taken is still recorded: `auditable_type` is deliberately
     * non-null in this table, and the kind is the first thing anyone reading
     * the log wants to know.
     */
    public function test_an_export_names_the_kind_taken_but_no_single_record(): void
    {
        Employee::factory()->create();

        $this->actingAs(User::factory()->role(User::ROLE_ADMIN)->create())
            ->get('/settings/data/export/employees');

        $entry = AuditLog::where('event', DataAccessLogger::EVENT_EXPORTED)->latest('id')->first();

        $this->assertNull($entry->auditable_id);
        $this->assertSame(Employee::class, $entry->auditable_type);
    }

    public function test_the_attendance_report_export_records_its_range(): void
    {
        /*
         * The export takes from/to and nothing else now, so there is no
         * `period` left to make them load-bearing. That was the fix for this
         * test asserting against whatever month the suite happened to run in;
         * the two dates are the only range the endpoint has.
         */
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/timekeeping/export?from=2026-08-01&to=2026-08-31')
            ->assertOk();

        $entry = AuditLog::where('event', DataAccessLogger::EVENT_EXPORTED)->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame('attendance-report', $entry->new_values['report']);
        // The range is what makes the row answer anything.
        $this->assertSame('2026-08-01', $entry->new_values['from']);
        $this->assertSame('2026-08-31', $entry->new_values['to']);
    }

    // --- What a stolen database dump would hold -----------------------------

    /**
     * `viewSensitive` decides who may *see* a TIN. This decides what is
     * readable in the file the database sits in — a different question, and
     * the one an application gate cannot answer at all.
     *
     * Asserted against the raw column rather than the model, because the cast
     * would decrypt it and the test would pass on plaintext.
     */
    public function test_government_identifiers_are_encrypted_at_rest(): void
    {
        $employee = Employee::factory()->create([
            'sss_number' => '34-1234567-8',
            'tin' => '123-456-789-000',
            'bank_account_number' => '0011-2233-4455',
        ]);

        $raw = DB::table('employees')->where('id', $employee->id)->first();

        foreach (['sss_number', 'tin', 'bank_account_number'] as $field) {
            $this->assertNotSame(
                $employee->{$field},
                $raw->{$field},
                "{$field} is still readable in the column.",
            );
        }

        // And the application still reads them back unchanged — an encryption
        // that costs the feature is not a trade anybody made.
        $employee->refresh();

        $this->assertSame('34-1234567-8', $employee->sss_number);
        $this->assertSame('0011-2233-4455', $employee->bank_account_number);
    }

    /**
     * The one thing encryption could plausibly have broken.
     *
     * `RecordIntegrityChecker` reports a number held by two people, and a
     * ciphertext differs per row even for identical input — so a duplicate
     * check written as a SQL `groupBy` would have gone silently blind here.
     * It loads the rows and compares in PHP, which is why this still works,
     * and this test is what stops somebody "optimising" it into SQL later.
     */
    public function test_duplicate_detection_survives_encryption(): void
    {
        Employee::factory()->create(['tin' => '123-456-789-000']);
        Employee::factory()->create(['tin' => '123-456-789-000']);

        // scan() returns one row per employee, each carrying its findings.
        $types = app(RecordIntegrityChecker::class)
            ->scan(Employee::query())
            ->flatMap(fn (array $row) => $row['findings'])
            ->pluck('type');

        $this->assertTrue(
            $types->contains('duplicate_number'),
            'Two employees share a TIN and nothing reported it.',
        );
    }

    /** @return array{0: User, 1: Employee, 2: EmployeeDocument} */
    private function uploadedDocument(): array
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/documents", [
            'type' => 'government_id',
            'title' => 'PhilSys ID',
            'file' => UploadedFile::fake()->create('philsys.pdf', 60, 'application/pdf'),
        ])->assertRedirect();

        return [$hr, $employee, $employee->documents()->firstOrFail()];
    }
}
