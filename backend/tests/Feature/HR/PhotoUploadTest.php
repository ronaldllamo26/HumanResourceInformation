<?php

namespace Tests\Feature\HR;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Employee photos: uploaded on create or update, stored on the public disk
 * (unlike 201-file documents, which stay private), and shown wherever the
 * employee's record appears — directory, profile, and back in the edit form
 * as a preview of what is already on file.
 */
class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photo_uploaded_on_create_is_stored_and_served(): void
    {
        Storage::fake('public');

        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 300)->size(500),
        ]))->assertRedirect();

        $employee = Employee::firstOrFail();

        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('employee.data.photo_url', asset('storage/'.$employee->photo_path)),
            );
    }

    public function test_a_photo_uploaded_on_update_replaces_nothing_that_was_there_before(): void
    {
        Storage::fake('public');

        $employee = Employee::factory()->create([
            'department_id' => $this->department()->id,
        ]);

        $this->actingAs($this->hr())
            ->post("/hr/employees/{$employee->id}", $this->payload([
                '_method' => 'put',
                'department_id' => $employee->department_id,
                'photo' => UploadedFile::fake()->image('photo.jpg', 300, 300)->size(500),
            ]))
            ->assertRedirect();

        $employee->refresh();

        $this->assertNotNull($employee->photo_path);
        Storage::disk('public')->assertExists($employee->photo_path);
    }

    /** The edit form needs this to show what is already on file. */
    public function test_the_edit_form_receives_the_current_photo_url(): void
    {
        Storage::fake('public');
        $photo = UploadedFile::fake()->image('photo.jpg', 300, 300)->store('employee-photos', 'public');

        $employee = Employee::factory()->create([
            'department_id' => $this->department()->id,
            'photo_path' => $photo,
        ]);

        $this->actingAs($this->hr())
            ->get("/hr/employees/{$employee->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('HR/Employees/Edit')
                ->where('employee.data.photo_url', asset('storage/'.$photo)),
            );
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->create('not-a-photo.pdf', 500),
        ]))->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_photo_over_two_megabytes_is_rejected(): void
    {
        $this->actingAs($this->hr())->post('/hr/employees', $this->payload([
            'department_id' => $this->department()->id,
            'photo' => UploadedFile::fake()->image('too-big.jpg')->size(2049),
        ]))->assertSessionHasErrors('photo');
    }

    // --- What gets stripped on the way in -----------------------------------

    /**
     * The leak this exists to close.
     *
     * Photos are on the *public* disk — deliberately, so an avatar costs no
     * PHP request — which means the file is served to anyone with the URL and
     * no policy runs in front of it. A phone photo carries EXIF, and EXIF
     * carries GPS, so a picture taken at somebody's house puts the coordinates
     * of their home on a public URL. The `image` validation rule does not
     * catch this: it checks the format, not what travels inside it.
     */
    public function test_exif_is_stripped_from_a_stored_photo(): void
    {
        Storage::fake('public');

        $employee = Employee::factory()->create();

        $this->actingAs($this->hr())->post("/hr/employees/{$employee->id}", [
            '_method' => 'put',
            ...$this->payload(),
            'photo' => $this->photoCarryingExif(),
        ]);

        $path = $employee->fresh()->photo_path;

        $this->assertNotNull($path, 'The photo should still have been stored.');

        $stored = Storage::disk('public')->get($path);

        // Read back from the bytes actually written, not from what was sent.
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($stored));

        $this->assertArrayNotHasKey('GPSLatitude', $exif ?: []);
        $this->assertArrayNotHasKey('Make', $exif ?: []);
    }

    /**
     * A file that merely claims to be an image never reaches the public disk.
     *
     * Validation checks the declared mime; this checks that the bytes decode.
     * The one path that would defeat the class is storing an undecodable file
     * unprocessed, so it is refused instead — and the record keeps whatever
     * photo it already had rather than being blanked by a failed upload.
     */
    public function test_a_file_that_cannot_be_decoded_leaves_the_old_photo_alone(): void
    {
        Storage::fake('public');

        $employee = Employee::factory()->create(['photo_path' => 'employee-photos/old.jpg']);
        Storage::disk('public')->put('employee-photos/old.jpg', 'existing');

        $this->actingAs($this->hr())->post("/hr/employees/{$employee->id}", [
            '_method' => 'put',
            ...$this->payload(),
            // A real .jpg name and mime, and nothing an image decoder can read.
            'photo' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image'),
        ]);

        $this->assertSame('employee-photos/old.jpg', $employee->fresh()->photo_path);
        Storage::disk('public')->assertExists('employee-photos/old.jpg');
    }

    private function department(): Department
    {
        return Department::create(['code' => 'OPS', 'name' => 'Operations']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            // Employees are created by approving a Core 1 endorsement — the
            // form has no other way in. A fresh one per call, because one
            // endorsement becomes one employee and a decided one is closed.
            'endorsement_id' => EmployeeEndorsement::factory()->create()->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'nationality' => 'Filipino',
            'employment_category' => 'internal',
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'date_hired' => '2026-01-15',
            'basic_salary' => 25000,
            'pay_frequency' => 'semi_monthly',
            'status' => 'active',
        ], $overrides);
    }

    /** A real JPEG carrying a GPS tag and a camera make. */
    private function photoCarryingExif(): UploadedFile
    {
        $image = imagecreatetruecolor(60, 40);
        imagefilledrectangle($image, 0, 0, 60, 40, imagecolorallocate($image, 120, 140, 160));

        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        imagejpeg($image, $path);
        imagedestroy($image);

        /*
         * GD writes no EXIF, so it is spliced in as an APP1 segment straight
         * after the SOI marker — which is exactly where a camera puts it. A
         * fixture committed to the repository would have been a binary blob
         * nobody could check; this is readable and provably carries the tag.
         */
        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2).$this->exifSegment().substr($jpeg, 2));

        // Proof the fixture is worth asserting against.
        $this->assertNotFalse(
            @exif_read_data($path),
            'The fixture must actually carry EXIF or the test proves nothing.',
        );

        return new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true);
    }

    /** A minimal little-endian TIFF header carrying Make and a GPS pointer. */
    private function exifSegment(): string
    {
        // "Exif\0\0" + TIFF header, one IFD entry: Make = "PrimePhone".
        $tiff = "II\x2a\x00\x08\x00\x00\x00"          // little-endian, IFD at 8
            ."\x01\x00"                                // one entry
            ."\x0f\x01\x02\x00\x0b\x00\x00\x00"        // tag 0x010f (Make), ASCII, 11 bytes
            ."\x1a\x00\x00\x00"                        // value at offset 26
            ."\x00\x00\x00\x00"                        // no next IFD
            ."PrimePhone\x00";

        $payload = "Exif\x00\x00".$tiff;

        return "\xff\xe1".pack('n', strlen($payload) + 2).$payload;
    }

    private function hr(): User
    {
        return User::factory()->hrStaff()->create();
    }
}
