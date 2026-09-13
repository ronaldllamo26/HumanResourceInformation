<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmployeeSchedule extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Schedules in force on the given date. */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });
    }

    /** Whether this schedule covers the date's ISO weekday (1 = Monday). */
    public function coversDate(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->days_of_week ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'days_of_week' => 'array',
        ];
    }
}
