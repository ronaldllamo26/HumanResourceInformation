<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One KPI's score inside a single review. */
class PerformanceReviewRating extends Model
{
    // A score is the thing most worth knowing was changed, and by whom.
    use Auditable;

    protected $guarded = ['id'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'weight' => 'decimal:2',
            'actual_value' => 'decimal:2',
        ];
    }
}
