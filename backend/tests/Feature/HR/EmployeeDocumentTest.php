<?php

namespace Tests\Feature\HR;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_upload_a_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $this->assertSame('contract.pdf', $document->file_name);
        Storage::disk(EmployeeService::DOCUMENT_DISK)->assertExists($document->file_path);
    }

    /**
     * Regression guard: documents used to be written to the public disk, which
     * made contracts and government IDs readable by URL without logging in.
     */
    public function test_documents_are_not_written_to_the_public_disk(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);
        Storage::fake('public');

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        Storage::disk('public')->assertMissing($document->file_path);
        Storage::disk(EmployeeService::DOCUMENT_DISK)->assertExists($document->file_path);
    }

    public function test_the_document_url_points_at_the_authorized_route_not_storage(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $hr = User::factory()->hrStaff()->create();
        $document = $this->upload($employee, $hr);

        $this->actingAs($hr)
            ->getJson("/api/v1/employees/{$employee->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.0.url', route('hr.employees.documents.download', [
                'employee' => $employee->id,
                'document' => $document->id,
            ]));
    }

    public function test_a_guest_cannot_download_a_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $this->post('/logout');

        $this->get("/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertRedirect('/login');
    }

    public function test_an_unrelated_employee_cannot_download_someone_elses_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $outsider = User::factory()->create();
        Employee::factory()->create(['user_id' => $outsider->id]);

        $this->actingAs($outsider)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_the_owning_employee_can_download_their_own_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $owner = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $owner->id]);
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $this->actingAs($owner)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload('contract.pdf');
    }

    // --- Previewing --------------------------------------------------------

    /**
     * The preview serves the same private file, so it has to be exactly as
     * gated as the download. A viewer that skipped the check would be a hole
     * straight past EmployeePolicy.
     */
    public function test_the_preview_serves_the_file_inline_rather_than_downloading_it(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();
        $document = $this->upload($employee, $hr);

        $response = $this->actingAs($hr)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/preview")
            ->assertOk();

        $this->assertStringStartsWith(
            'inline',
            $response->headers->get('content-disposition'),
        );
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_a_guest_cannot_preview_a_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $this->post('/logout');

        $this->get("/hr/employees/{$employee->id}/documents/{$document->id}/preview")
            ->assertRedirect('/login');
    }

    public function test_an_unrelated_employee_cannot_preview_someone_elses_document(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $employee = Employee::factory()->create();
        $document = $this->upload($employee, User::factory()->hrStaff()->create());

        $outsider = User::factory()->create();
        Employee::factory()->create(['user_id' => $outsider->id]);

        $this->actingAs($outsider)
            ->get("/hr/employees/{$employee->id}/documents/{$document->id}/preview")
            ->assertForbidden();
    }

    /** A .docx has no browser viewer — offer the download only. */
    public function test_the_resource_reports_what_a_document_can_be_previewed_as(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();
        $this->upload($employee, $hr);

        $employee->documents()->update(['mime_type' => 'image/jpeg']);
        $this->assertSame('image', $this->firstDocument($employee, $hr)['preview_as']);

        $employee->documents()->update(['mime_type' => 'application/pdf']);
        $this->assertSame('pdf', $this->firstDocument($employee, $hr)['preview_as']);

        $employee->documents()->update(['mime_type' => 'text/plain']);
        $this->assertSame('text', $this->firstDocument($employee, $hr)['preview_as']);

        $employee->documents()->update([
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $this->assertNull($this->firstDocument($employee, $hr)['preview_as']);
    }

    public function test_a_document_id_from_another_employee_is_rejected(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employeeA = Employee::factory()->create();
        $employeeB = Employee::factory()->create();
        $document = $this->upload($employeeA, $hr);

        $this->actingAs($hr)
            ->get("/hr/employees/{$employeeB->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_non_hr_roles_cannot_upload_documents(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post("/hr/employees/{$employee->id}/documents", [
            'type' => 'contract',
            'title' => 'Self upload',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_deleting_a_document_removes_the_stored_file(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();
        $document = $this->upload($employee, $hr);
        $path = $document->file_path;

        $this->actingAs($hr)
            ->delete("/hr/employees/{$employee->id}/documents/{$document->id}")
            ->assertRedirect();

        Storage::disk(EmployeeService::DOCUMENT_DISK)->assertMissing($path);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_oversized_and_disallowed_files_are_rejected(): void
    {
        Storage::fake(EmployeeService::DOCUMENT_DISK);

        $hr = User::factory()->hrStaff()->create();
        $employee = Employee::factory()->create();

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/documents", [
            'type' => 'contract',
            'title' => 'Too big',
            'file' => UploadedFile::fake()->create('huge.pdf', 20480, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($hr)->post("/hr/employees/{$employee->id}/documents", [
            'type' => 'contract',
            'title' => 'Executable',
            'file' => UploadedFile::fake()->create('payload.exe', 10),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('employee_documents', 0);
    }

    /** @return array<string, mixed> the first document as the page receives it */
    private function firstDocument(Employee $employee, User $actor): array
    {
        return $this->actingAs($actor)
            ->get("/hr/employees/{$employee->id}")
            ->viewData('page')['props']['employee']['data']['documents'][0];
    }

    private function upload(Employee $employee, User $actor): EmployeeDocument
    {
        $this->actingAs($actor)->post("/hr/employees/{$employee->id}/documents", [
            'type' => 'contract',
            'title' => 'Employment Contract 2026',
            'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        return $employee->documents()->firstOrFail();
    }
}
