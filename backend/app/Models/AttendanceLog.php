<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use Auditable, HasFactory;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_UNDERTIME = 'undertime';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_REST_DAY = 'rest_day';

    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_ABSENT,
        self::STATUS_LATE,
        self::STATUS_UNDERTIME,
        self::STATUS_ON_LEAVE,
        self::STATUS_HOLIDAY,
        self::STATUS_REST_DAY,
    ];

    public const SOURCES = ['manual', 'biometric', 'web', 'import'];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, $value) => $q->whereDate('log_date', '>=', $value))
            ->when($to, fn (Builder $q, $value) => $q->whereDate('log_date', '<=', $value));
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $q, $value) => $q->where('employee_id', $value),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            )
            /*
             * Days where somebody arrived late, which is *not* `status = late`.
             * A record can be marked `undertime` and still carry late minutes —
             * the status holds one label and the day can be two things at once.
             *
             * It exists so the "Late Instances" tile can link to the rows it
             * counted: the summary counts `late_minutes > 0`, and a tile
             * reading 53 that opens a list of 41 is worse than a tile that
             * does not open at all.
             */
            ->when(
                filter_var($filters['late'] ?? null, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $q->where('late_minutes', '>', 0),
            )
            /*
             * Days somebody turned up, which is three statuses rather than one:
             * arriving late or leaving early is still attendance, and
             * `TimekeepingService::summary()` has always counted it that way.
             *
             * Without this the "Days Present" tile linked to `status=present`
             * and opened 581 rows while reading 1,230 — the same class of
             * mismatch as `late`, found the same way, by clicking it.
             */
            ->when(
                filter_var($filters['attended'] ?? null, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $q) => $q->whereIn('status', [
                    self::STATUS_PRESENT,
                    self::STATUS_LATE,
                    self::STATUS_UNDERTIME,
                ]),
            )
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $q, $value) => $q->whereHas(
                    'employee',
                    fn (Builder $inner) => $inner->where('department_id', $value),
                ),
            );
    }

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'break_out' => 'datetime',
            'break_in' => 'datetime',
            'hours_worked' => 'decimal:2',
        ];
    }
}
