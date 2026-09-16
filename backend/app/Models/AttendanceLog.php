<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee's day: the punches, and what they came to. */
class AttendanceLog extends Model
{
    use Auditable;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_UNDERTIME = 'undertime';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_REST_DAY = 'rest_day';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_ON_LEAVE = 'on_leave';

    /** Clocked in, not yet out — nothing can be computed from it. */
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUSES = [
        self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_UNDERTIME, self::STATUS_ABSENT,
        self::STATUS_REST_DAY, self::STATUS_HOLIDAY, self::STATUS_ON_LEAVE, self::STATUS_INCOMPLETE,
    ];

    /** A day somebody came in, however late or short. */
    public const WORKED_STATUSES = [self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_UNDERTIME];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_CORRECTION = 'correction';

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to);
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
        ];
    }
}
