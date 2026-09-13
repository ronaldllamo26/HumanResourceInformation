<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an employee's scorecard for a cycle: which KPI, at what weight,
 * against what target.
 */
class EmployeeKpi extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function reviewCycle(): BelongsTo
    {
        return $this->belongsTo(ReviewCycle::class);
    }

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'target_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
        ];
    }
}
