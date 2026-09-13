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
 * Filing a stack of scans against the people they name.
 *
 * The single-document upload already knows whose file it is — HR opened the
 * record to get there. Here nobody has been opened, so the question runs the
 * other way: whose is this? The rules that answer it are the scanner's own,
 * reused rather than restated, and the precedence is the same one the upload
 * form applies — a number on a 201 file outranks a name, because a number
 * belongs to one person and a name is shared by thousands.
 *
 * Nothing here is filed without a person confirming it. That is the whole
 * reason it is a review screen and not a background job.
 */
class DocumentBatchTest extends TestCase
{
    use RefreshDatabase;

    // --- Whose is it? --------------------------------------------------------

    public function test_a_document_is_matched_to_the_name_it_carries(): void
    {
        $juan = Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $rows = $this->filerReading([[
            'type' => 'clearance',
            'name_on_document' => 'DELA CRUZ, JUAN',
        ]])->examine([$this->image()], Employee::all());

        $this->assertSame($juan->id, $rows[0]['employee_id']);
        $this->assertSame(BulkDocumentFiler::BY_NAME, $rows[0]['matched_by']);
    }

    /**
     * The number settles it even when the name reading looks wrong — the same
     * precedence that stopped the upload form refusing a real licence printed
     * without its surname.
     */
    public function test_an_id_number_outranks_the_name(): void
    {
        $juan = Employee::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'drivers_license_number' => 'N01-23-456789',
        ]);

        $rows = $this->filerReading([[
            'type' => 'drivers_license',
            'name_on_document' => 'SOMEBODY ELSE ENTIRELY',
            'document_number' => 'LICENSE NO.: N01-23-456789',
        ]])->examine([$this->image()], Employee::all());

        $this->assertSame($juan->id, $rows[0]['employee_id']);
        $this->assertSame(BulkDocumentFiler::BY_NUMBER, $rows[0]['matched_by']);
    }

    /**
     * Two people it could be is not a match. Taking the first would file the
     * document under a coin toss, and the error is silent afterwards — nobody
     * looks through another person's 201 file for something that should never
     * have been there.
     */
    public function test_two_people_fitting_one_name_is_not_a_match(): void
    {
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $rows = $this->filerReading([[
            'type' => 'clearance',
            'name_on_document' => 'DELA CRUZ, JUAN',
        ]])->examine([$this->image()], Employee::all());

        $this->assertNull($rows[0]['employee_id']);
        $this->assertSame(BulkDocumentFiler::AMBIGUOUS, $rows[0]['matched_by']);
        $this->assertCount(2, $rows[0]['candidates']);
    }

    public function test_a_name_nobody_answers_to_is_left_unmatched(): void
    {
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $rows = $this->filerReading([[
            'type' => 'clearance',
            'name_on_document' => 'RIZAL, JOSE PROTACIO',
        ]])->examine([$this->image()], Employee::all());

        $this->assertNull($rows[0]['employee_id']);
        $this->assertSame(BulkDocumentFiler::UNMATCHED, $rows[0]['matched_by']);
    }

    /** An unreadable image is a document somebody assigns by hand, not a failure. */
    public function test_an_unreadable_file_still_appears_for_assignment(): void
    {
        Employee::factory()->create();

        $rows = $this->filerReading([null])->examine([$this->image('blurry.jpg')], Employee::all());

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['scanned']);
        $this->assertSame('blurry.jpg', $rows[0]['file_name']);
    }

    /** Examining is a proposal. Nothing reaches a 201 file until it is confirmed. */
    public function test_examining_files_nothing(): void
    {
        Employee::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $this->filerReading([[
            'type' => 'clearance',
            'name_on_document' => 'DELA CRUZ, JUAN',
        ]])->examine([$this->image()], Employee::all());

        $this->assertDatabaseCount('employee_documents', 0);
    }

    // --- Filing what was confirmed -------------------------------------------

    public function test_it_files_what_the_person_confirmed(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->hrStaff()->create());

        $employee = Employee::factory()->create();

        $result = $this->filerReading([])->file(
            [$this->image()],
            [[
                'employee_id' => $employee->id,
                'type' => 'clearance',
                'title' => 'NBI Clearance',
                'issued_at' => '2026-03-14',
                'expires_at' => '2027-03-14',
            ]],
            Employee::all(),
        );

        $this->assertSame(1, $result['filed']);
        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $employee->id,
            'type' => 'clearance',
        ]);
    }

    /** A document nobody claimed is skipped, never filed somewhere plausible. */
    public function test_an_unassigned_document_is_skipped(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->hrStaff()->create());
        Employee::factory()->create();

        $result = $this->filerReading([])->file(
            [$this->image()],
            [['employee_id' => null, 'type' => 'clearance', 'title' => '', 'issued_at' => null, 'expires_at' => null]],
            Employee::all(),
        );

        $this->assertSame(0, $result['filed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    /**
     * The scope is re-checked at the write, not trusted from the form: the
     * review screen was built from what this user may see, and the request
     * that follows it is held to the same list.
     */
    public function test_an_employee_outside_the_users_scope_cannot_be_filed_against(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->hrStaff()->create());

        $outsider = Employee::factory()->create();

        $result = $this->filerReading([])->file(
            [$this->image()],
            [['employee_id' => $outsider->id, 'type' => 'clearance', 'title' => '', 'issued_at' => null, 'expires_at' => null]],
            Employee::whereKeyNot($outsider->id)->get(),
        );

        $this->assertSame(0, $result['filed']);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    /** A hallucinated type never reaches the database. */
    public function test_a_type_outside_the_list_falls_back_to_other(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->hrStaff()->create());
        $employee = Employee::factory()->create();

        $this->filerReading([])->file(
            [$this->image()],
            [['employee_id' => $employee->id, 'type' => 'passport-ish', 'title' => 'X', 'issued_at' => null, 'expires_at' => null]],
            Employee::all(),
        );

        $this->assertDatabaseHas('employee_documents', ['type' => 'other']);
    }

    // --- The way in -----------------------------------------------------------

    public function test_the_screen_is_hr_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPERVISOR]))
            ->get('/hr/employees/documents/batch')
            ->assertForbidden();
    }

    /** Also the guard that the wildcard resource route does not swallow it. */
    public function test_hr_can_open_the_screen(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/employees/documents/batch')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('HR/Employees/DocumentBatch'));
    }

    public function test_the_batch_is_capped(): void
    {
        $files = array_map(fn ($i) => UploadedFile::fake()->image("s{$i}.jpg"), range(1, 21));

        $this->actingAs(User::factory()->hrStaff()->create())
            ->postJson('/hr/employees/documents/batch/examine', ['files' => $files])
            ->assertJsonValidationErrors('files');
    }

    // --- Fixtures -------------------------------------------------------------

    /** A filer whose scanner returns one canned reading per file, in order. */
    private function filerReading(array $readings): BulkDocumentFiler
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

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
