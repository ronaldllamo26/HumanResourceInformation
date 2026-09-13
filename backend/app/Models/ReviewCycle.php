<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewCycle extends Model
{
    use Auditable, HasFactory;

    /** Being set up; KPIs can still be assigned. */
    public const STATUS_DRAFT = 'draft';

    /** Reviewers can now submit evaluations. */
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_OPEN,
        self::STATUS_IN_REVIEW,
        self::STATUS_CLOSED,
    ];

    public const TYPES = ['quarterly', 'semi_annual', 'annual'];

    protected $guarded = ['id'];

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function employeeKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class);
    }

    /** Evaluations may only be filed while the cycle is taking submissions. */
    public function acceptsSubmissions(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_REVIEW], true);
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'review_due_date' => 'date',
        ];
    }
}
