<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use Auditable;

    public const TYPES = [
        'contract',
        'resume',
        'government_id',
        'clearance',
        // PSA civil registry: birth and marriage certificates, CENOMAR. Kept
        // apart from `certificate`, which means a training or TESDA one — a
        // birth certificate filed under that would sit in the wrong renewal
        // window and read as a qualification.
        'psa',
        'certificate',
        /*
         * Schooling, kept apart from `certificate` for the same reason `psa`
         * is: a diploma is not a training card. It never expires, so filing
         * one under a type the renewal window watches would put a degree in a
         * queue to be chased forever — and the qualification records on the
         * 201 file point at these two for the paper behind an attainment.
         */
        'diploma',
        'transcript',
        'medical',
        'drivers_license',
        'other',
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'file_size' => 'integer',
            'filed_automatically' => 'boolean',
        ];
    }
}
