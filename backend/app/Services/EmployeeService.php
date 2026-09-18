<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Notifications\AccountProvisioned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Business logic for Module 1 — shared by the Inertia controller and the REST API
 * so both entry points behave identically.
 */
class EmployeeService
{
    /** Documents live on a private disk; photos stay public so avatars can be
     *  rendered by <img> without a PHP request per row. */
    public const DOCUMENT_DISK = 'local';

    public const PHOTO_DISK = 'public';

    /** Temp password handed to HR when a self-service login is provisioned. */
    public ?string $generatedPassword = null;

    public function __construct(private readonly PhotoStore $photos) {}

    /**
     * Restrict the directory to what the viewer is allowed to see.
     */
    public function scopedQuery(User $user): Builder
    {
        // `client` is eager-loaded for the directory's Assignment column —
        // without it every deployed row costs its own query.
        $query = Employee::query()->with([
            'department:id,name',
            'position:id,title',
            'client:id,code,name',
        ]);

        if ($user->isHrAdmin()) {
            return $query;
        }

        if ($user->isSupervisor() && $user->employee) {
            // Own record plus direct reports.
            return $query->where(function (Builder $inner) use ($user) {
                $inner->where('supervisor_id', $user->employee->id)
                    ->orWhere('id', $user->employee->id);
            });
        }

        return $query->where('user_id', $user->id);
    }

    public function create(array $data, ?UploadedFile $photo = null): Employee
    {
        return DB::transaction(function () use ($data, $photo) {
            $createAccount = (bool) ($data['create_user_account'] ?? false);
            $role = $data['user_role'] ?? User::ROLE_EMPLOYEE;

            unset($data['create_user_account'], $data['user_role'], $data['photo']);

            $data['employee_number'] = Employee::nextEmployeeNumber();

            // Re-encoded rather than stored — see PhotoStore for why. A file
            // that cannot be decoded leaves the record with no photo rather
            // than with an unprocessed one on a public URL.
            if ($photo && $stored = $this->photos->store($photo)) {
                $data['photo_path'] = $stored;
            }

            if ($createAccount) {
                $data['user_id'] = $this->provisionUserAccount($data, $role)->id;
            }

            return Employee::create($data);

        });
    }

    public function update(Employee $employee, array $data, ?UploadedFile $photo = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $photo) {
            unset($data['create_user_account'], $data['user_role'], $data['photo'], $data['employee_number']);

            /*
             * The old photo goes only once the new one is written. Deleting
             * first and then failing to decode would leave the record with no
             * avatar and nothing to put back — a failed upload must not cost
             * the picture that was already there.
             */
            if ($photo && $stored = $this->photos->store($photo)) {
                $this->deletePhoto($employee);
                $data['photo_path'] = $stored;
            }

            $employee->update($data);

            // Keep the linked login's contact details aligned.
            if ($employee->user) {
                $employee->user->update([
                    'name' => $employee->full_name,
                    'email' => $employee->email ?? $employee->user->email,
                ]);
            }

            return $employee->refresh();
        });
    }

    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            // Soft delete — the 201 file is retained for audit and payroll history.
            $employee->update([
                'status' => 'inactive',
                'employment_status' => 'terminated',
                'date_separated' => now(),
            ]);
            $employee->delete();

            if ($employee->user) {
                $employee->user->update(['is_active' => false]);
                $employee->user->delete();
            }
        });
    }

    public function restore(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $employee->restore();
            $employee->update([
                'status' => 'active',
                'employment_status' => 'regular',
                'date_separated' => null,
                'separation_reason' => null,
            ]);

            if ($employee->user_id) {
                $user = User::withTrashed()->find($employee->user_id);
                if ($user) {
                    $user->restore();
                    $user->update(['is_active' => true]);
                }
            }
        });
    }

    public function storeDocument(Employee $employee, array $data, UploadedFile $file): EmployeeDocument
    {
        // Private disk: 201-file documents hold contracts and government IDs, so
        // they are never web-served directly — downloads go through an
        // authorized controller route.
        $path = $file->store("employee-documents/{$employee->id}", self::DOCUMENT_DISK);

        return $employee->documents()->create([
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
            /*
             * Whether the batch filer decided this row itself. Defaults false,
             * so every hand-filed document — which is every one that does not
             * come through `BulkDocumentFiler::process()` — reads as what it
             * is. `uploaded_by` still names the person who started the batch;
             * this records how the row was reached, not who reached it.
             */
            'filed_automatically' => (bool) ($data['filed_automatically'] ?? false),
        ]);
    }

    public function deleteDocument(EmployeeDocument $document): void
    {
        Storage::disk(self::DOCUMENT_DISK)->delete($document->file_path);
        $document->delete();
    }

    /** Headline counts for the module dashboard. */
    public function statistics(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'on_leave' => (clone $query)->where('status', 'on_leave')->count(),
            'probationary' => (clone $query)->where('employment_status', 'probationary')->count(),
        ];
    }

    private function provisionUserAccount(array $data, string $role): User
    {
        $this->generatedPassword = User::generatePassword();
        $otpEmail = filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null;

        $user = User::create([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'email' => $data['email'],
            'password' => $this->generatedPassword,
            'visible_password' => Crypt::encryptString($this->generatedPassword),
            'role' => $role,
            'is_active' => true,
            // HR reads this password out to the employee, so two people know
            // it before it is ever used. RequirePasswordChange holds the
            // account on the Security screen until that stops being true.
            'must_change_password' => true,
            'otp_email' => $otpEmail,
        ]);

        if ($user->otp_email) {
            try {
                $user->notify(new AccountProvisioned($this->generatedPassword, $role));
            } catch (\Throwable $e) {
                Log::error('Failed to email provisioned credentials for employee user: '.$e->getMessage());
            }
        }

        return $user;
    }

    private function deletePhoto(Employee $employee): void
    {
        if ($employee->photo_path) {
            Storage::disk(self::PHOTO_DISK)->delete($employee->photo_path);
        }
    }
}
