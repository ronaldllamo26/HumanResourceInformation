<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use Auditable, HasFactory;

    /** Computed but not yet submitted; can be recomputed or deleted. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FOR_APPROVAL = 'for_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FOR_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    /** Statuses whose payslips are finished enough to report or pay against. */
    public const REPORTABLE = [self::STATUS_APPROVED, self::STATUS_PAID];

    protected $guarded = ['id'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Only a draft may be recomputed or thrown away. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Once approved, the figures are final and loans have been amortised. */
    public function isFinal(): bool
    {
        return in_array($this->status, self::REPORTABLE, true);
    }

    /**
     * Runs whose figures may be reported on or paid against.
     *
     * Kept here rather than copied into each screen: 13th-month pay,
     * compliance remittances, and final pay all have to agree on what
     * "already earned" means, and three private copies of the list will
     * eventually disagree. A draft is still being corrected.
     */
    public function scopeReportable(Builder $query): Builder
    {
        return $query->whereIn('status', self::REPORTABLE);
    }

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',

            /*
             * When the bank credited it, which is a third date and not the
             * same as either above. A transfer sent on Friday and confirmed on
             * Monday is one event with two of its own, and `updated_at` would
             * only ever hold the second.
             */
            'disbursed_at' => 'datetime',
            'total_gross' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'total_net' => 'decimal:2',
        ];
    }
}
