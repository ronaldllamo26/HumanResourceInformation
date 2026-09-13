<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceReview extends Model
{
    use Auditable, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    /** The employee has seen and signed off on the review. */
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_ACKNOWLEDGED,
    ];

    /** The four perspectives of a 360 review. */
    public const REVIEWER_TYPES = ['self', 'supervisor', 'peer', 'subordinate'];

    protected $guarded = ['id'];

    /** Same reason as Payslip::employee() — a finished review is history. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewCycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(PerformanceReviewRating::class);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            )
            ->when(
                $filters['review_cycle_id'] ?? null,
                fn (Builder $q, $value) => $q->where('review_cycle_id', $value),
            )
            ->when(
                $filters['reviewer_type'] ?? null,
                fn (Builder $q, $value) => $q->where('reviewer_type', $value),
            )
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $q, $value) => $q->where('employee_id', $value),
            );
    }

    protected function casts(): array
    {
        return [
            'overall_rating' => 'decimal:2',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
