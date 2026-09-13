<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class SalaryAdjustment extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Signed change. Negative is legitimate — a demotion or a correction. */
    public function getDifferenceAttribute(): float
    {
        return round((float) $this->new_salary - (float) $this->previous_salary, 2);
    }

    /**
     * Dated ahead of today, so it is a decision already made that has not
     * taken effect yet. It must not be treated as the employee's rate.
     */
    public function isScheduled(): bool
    {
        return $this->effective_date->isAfter(Carbon::today());
    }

    /** In force on the given date. */
    public function scopeEffectiveBy(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('effective_date', '<=', $date->toDateString());
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $q, $value) => $q->where('employee_id', $value),
            )
            ->when(
                $filters['reason'] ?? null,
                fn (Builder $q, $value) => $q->where('reason', $value),
            );
    }

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'previous_salary' => 'decimal:2',
            'new_salary' => 'decimal:2',
        ];
    }
}
