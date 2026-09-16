<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee's shift and rest days from a date onward.
 *
 * Kept as history rather than a single column: moving somebody to nights in
 * October must not change how their September was computed.
 */
class EmployeeShift extends Model
{
    use Auditable;

    /** ISO weekday numbers, Monday = 1 … Sunday = 7. */
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isRestDay(Carbon $date): bool
    {
        return in_array($date->isoWeekday(), array_map('intval', $this->rest_days ?? []), true);
    }

    protected function casts(): array
    {
        return [
            'rest_days' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
