<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to correct one day of somebody's DTR.
 *
 * Auditable, and that is the point of the table as much as the workflow is:
 * a corrected time record is a corrected payslip, so what the day said before,
 * who asked for the change, and who allowed it all have to survive the change.
 */
class AttendanceAdjustment extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $inner, $value) => $inner->where('status', $value),
            )
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $inner, $value) => $inner->where('employee_id', $value),
            );
    }

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }
}
