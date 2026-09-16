<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payroll period's attendance, closed once checked.
 *
 * Closing freezes every record, overtime decision and correction dated inside
 * the period, so the figures payroll computes from cannot move underneath it.
 */
class AttendanceCutoff extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = ['id'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
