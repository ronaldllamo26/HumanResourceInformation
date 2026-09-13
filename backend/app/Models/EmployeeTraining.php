<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed training or certification.
 *
 * The certificate itself belongs in `employee_documents`, where the scanner
 * reads it and CredentialExpiryScanner watches it lapse. This row records the
 * fact that the training happened, which survives the paper going missing.
 */
class EmployeeTraining extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * `expired`, `expiring`, or `valid` — and null for a qualification that
     * does not lapse.
     *
     * The window is read from `credentials.warning_days.certificate` rather
     * than held here, so this row and the Credentials screen cannot come to
     * disagree about the same TESDA card. Null means "does not expire", not
     * "nobody typed it": the form says so explicitly, which is the same
     * distinction the document scanner draws.
     */
    public function expiryState(): ?string
    {
        if ($this->expires_at === null) {
            return null;
        }

        $days = (int) config(
            'credentials.warning_days.certificate',
            config('credentials.default_warning_days', 30),
        );

        return match (true) {
            $this->expires_at->isPast() => 'expired',
            $this->expires_at->lte(now()->addDays($days)) => 'expiring',
            default => 'valid',
        };
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'date',
            'expires_at' => 'date',
        ];
    }
}
