<?php

namespace Tests\Feature\HR;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Bulk import of an existing workforce.
 *
 * The rule the whole feature turns on: a preview writes nothing. Everything
 * else here is about which rows are refused outright and which are created
 * with a note, and that split follows the system's own — a missing government
 * number does not stop somebody working, so it must not stop them being
 * recorded either.
 */
class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    // --- The rule the feature turns on -------------------------------------

    public function test_a_preview_creates_nothing(): void
    {
        $result = $this->importer()->preview(
            $this->csv('Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,,,,,'),
        );

        $this->assertSame(1, $result['summary']['ready']);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_an_import_creates_the_rows_a_preview_called_ready(): void
    {
        $result = $this->importer()->import(
            $this->csv("Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,,,,,\n"
                .'Santos,Maria,,2026-02-01,18500,internal,,probationary,,,,,,'),
        );

        $this->assertSame(2, $result['summary']['ready']);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseHas('employees', ['last_name' => 'Dela Cruz', 'basic_salary' => 25000]);
    }

    /** A number is taken at commit, never at preview — previewing twice must not burn two. */
    public function test_employee_numbers_are_generated_and_not_spent_by_a_preview(): void
    {
        $file = $this->csv('Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,,,,,');

        $this->importer()->preview($file);
        $this->importer()->preview($file);
        $this->importer()->import($file);

        $this->assertSame('PPM-'.now()->year.'-0001', Employee::first()->employee_number);
    }

    // --- What a spreadsheet actually contains -------------------------------

    /**
     * Carbon reads a slashed date month-first; the Philippines writes it
     * day-first. Unguarded, "15/02/2026" throws and "05/02/2026" silently
     * becomes 2 May — a hire date six weeks out, which moves regularisation,
     * 13th-month proration, and the first payslip with it.
     */
    public function test_a_day_first_date_is_read_as_written(): void
    {
        $this->importer()->import(
            $this->csv('Santos,Maria,,15/02/2026,18500,internal,,regular,,,,,,'),
        );

        $this->assertSame('2026-02-15', Employee::first()->date_hired->toDateString());
    }

    /** The one case that can be wrong is the one case somebody is told about. */
    public function test_an_ambiguous_date_is_read_day_first_and_reported(): void
    {
        $result = $this->importer()->preview(
            $this->csv('Santos,Maria,,05/02/2026,18500,internal,,regular,,,,,,'),
        );

        $this->assertSame('warning', $result['rows'][0]['status']);
        $this->assertStringContainsString('5 February 2026', implode(' ', $result['rows'][0]['messages']));
    }

    public function test_a_salary_keeps_its_value_through_commas_and_a_currency_symbol(): void
    {
        $this->importer()->import(
            $this->csv('Dela Cruz,Juan,,2026-01-15,"PHP 25,000.50",internal,,regular,,,,,,'),
        );

        $this->assertSame('25000.50', Employee::first()->basic_salary);
    }

    // --- The agency rules ---------------------------------------------------

    public function test_an_external_employee_without_a_client_is_refused(): void
    {
        $result = $this->importer()->preview(
            $this->csv('Cruz,Jose,,2026-01-15,20000,external,,regular,,,,,,'),
        );

        $this->assertSame('error', $result['rows'][0]['status']);
        $this->assertDatabaseCount('employees', 0);
    }

    /**
     * Prohibited rather than ignored, exactly as on the form: a stale client
     * on internal staff keeps them in that client's billing and headcount.
     */
    public function test_internal_staff_cannot_be_filed_against_a_client(): void
    {
        Client::create(['name' => 'Metro Logistics', 'code' => 'MTL', 'is_active' => true]);

        $result = $this->importer()->preview(
            $this->csv('Tan,Rosa,,2026-01-15,20000,internal,MTL,regular,,,,,,'),
        );

        $this->assertSame('error', $result['rows'][0]['status']);
    }

    public function test_a_client_is_matched_by_name_or_by_code(): void
    {
        $client = Client::create(['name' => 'Metro Logistics', 'code' => 'MTL', 'is_active' => true]);

        $this->importer()->import(
            $this->csv("Cruz,Jose,,2026-01-15,20000,external,MTL,regular,,,,,,\n"
                .'Reyes,Pedro,,2026-01-15,20000,external,Metro Logistics,regular,,,,,,'),
        );

        $this->assertSame(2, Employee::where('client_id', $client->id)->count());
    }

    /**
     * A category is never assumed. Filing an agency's deployed staff as
     * internal by omission keeps them off a client's headcount, which is the
     * error nobody goes looking for.
     */
    public function test_a_missing_category_column_stops_the_whole_file(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'employees.csv',
            "last_name,first_name,date_hired,basic_salary,employment_status\nDela Cruz,Juan,2026-01-15,25000,regular",
        );

        $result = $this->importer()->preview($file);

        $this->assertSame(0, $result['summary']['total']);
        $this->assertStringContainsString('employment_category', $result['errors'][0]);
    }

    // --- Refused versus noted ------------------------------------------------

    public function test_a_duplicate_employee_number_is_refused(): void
    {
        Employee::factory()->create(['employee_number' => 'PPM-2026-0001']);

        $result = $this->importer()->preview(
            $this->csv('Reyes,Pedro,PPM-2026-0001,2026-01-15,20000,internal,,regular,,,,,,'),
        );

        $this->assertSame('error', $result['rows'][0]['status']);
    }

    /** The database cannot see a collision between two rows of the same file. */
    public function test_two_rows_sharing_an_email_are_caught_inside_the_file(): void
    {
        $result = $this->importer()->preview(
            $this->csv("Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,juan@x.test,,,,\n"
                .'Reyes,Pedro,,2026-01-15,20000,internal,,regular,,juan@x.test,,,,'),
        );

        $this->assertSame('error', $result['rows'][1]['status']);
        $this->assertStringContainsString('row 2', implode(' ', $result['rows'][1]['messages']));
    }

    /**
     * A department named in the file but absent from the system is a warning:
     * master data is maintained behind `manageOrganization`, and an import that
     * invented rows would let a spreadsheet reshape the org chart.
     */
    public function test_an_unknown_department_leaves_the_employee_unfiled_rather_than_creating_it(): void
    {
        $result = $this->importer()->import(
            $this->csv('Ong,Ben,,2026-01-15,20000,internal,,regular,Nonexistent,,,,,'),
        );

        $this->assertSame('warning', $result['rows'][0]['status']);
        $this->assertNull(Employee::first()->department_id);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_a_known_department_is_matched_case_insensitively(): void
    {
        $department = Department::create(['name' => 'Fleet Operations', 'code' => 'FLT', 'is_active' => true]);

        $this->importer()->import(
            $this->csv('Ong,Ben,,2026-01-15,20000,internal,,regular,fleet operations,,,,,'),
        );

        $this->assertSame($department->id, Employee::first()->department_id);
    }

    /** Reported, never refused — Compliance chases these at remittance time. */
    public function test_missing_government_numbers_are_a_warning_not_a_refusal(): void
    {
        $result = $this->importer()->import(
            $this->csv('Ong,Ben,,2026-01-15,20000,internal,,regular,,,,,,'),
        );

        $this->assertSame('warning', $result['rows'][0]['status']);
        $this->assertDatabaseCount('employees', 1);
    }

    // --- The way in ----------------------------------------------------------

    public function test_the_screen_is_closed_to_anyone_who_cannot_create_an_employee(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get('/hr/employees/import')
            ->assertForbidden();
    }

    public function test_hr_can_open_the_screen(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/employees/import')
            ->assertOk();
    }

    /** The wildcard route must not swallow /hr/employees/import. */
    public function test_the_import_route_is_not_read_as_an_employee_id(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->get('/hr/employees/import')
            ->assertInertia(fn ($page) => $page->component('HR/Employees/Import'));
    }

    public function test_a_preview_over_http_writes_nothing(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->postJson('/hr/employees/import', [
                'file' => $this->csv('Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,,,,,'),
            ])
            ->assertOk()
            ->assertJsonPath('summary.ready', 1);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_commit_over_http_creates_and_redirects(): void
    {
        $this->actingAs(User::factory()->hrStaff()->create())
            ->post('/hr/employees/import', [
                'file' => $this->csv('Dela Cruz,Juan,,2026-01-15,25000,internal,,regular,,,,,,'),
                'commit' => true,
            ])
            ->assertRedirect('/hr/employees');

        $this->assertDatabaseCount('employees', 1);
    }

    private function csv(string $body): UploadedFile
    {
        $header = 'last_name,first_name,employee_number,date_hired,basic_salary,'
            .'employment_category,client,employment_status,department,email,sss,philhealth,pagibig,tin';

        return UploadedFile::fake()->createWithContent(
            'employees.csv',
            $header."\n".$body,
        );
    }

    private function importer(): EmployeeImporter
    {
        return app(EmployeeImporter::class);
    }
}
