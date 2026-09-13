<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A disciplinary action on record: a warning, or a suspension with dates.
 *
 * Recorded by Core 4 (safety and discipline) or by HR directly. **Nothing here
 * changes a payslip or a time record on its own** — see the migration for why.
 * `PayrollReadinessChecker` reads these and raises an unpaid suspension as a
 * warning before the money is computed; HR keys the days or decides not to.
 */
class DisciplinaryAction extends Model
{
    use Auditable;

    public const TYPE_VERBAL_WARNING = 'verbal_warning';

    public const TYPE_WRITTEN_WARNING = 'written_warning';

    public const TYPE_FINAL_WARNING = 'final_warning';

    public const TYPE_SUSPENSION = 'suspension';

    public const TYPES = [
        self::TYPE_VERBAL_WARNING,
        self::TYPE_WRITTEN_WARNING,
        self::TYPE_FINAL_WARNING,
        self::TYPE_SUSPENSION,
    ];

    /**
     * Dismissal is deliberately not one of these.
     *
     * Ending somebody's employment runs through Separation & Final Pay, which
     * snapshots what is owed, settles the loans, and follows payroll's
     * separation of duties. A `type` here that marked somebody dismissed would
     * be a second way to end an employment that skips all three.
     */
    public const SOURCES = ['core4', 'hr'];

    protected $fillable = [
        'employee_id',
        'source',
        'reference',
        'type',
        'reason',
        'effective_from',
        'effective_to',
        'is_unpaid',
        'issued_by',
        'notes',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Only a suspension has a span of days; a warning is a single date. */
    public function isSuspension(): bool
    {
        return $this->type === self::TYPE_SUSPENSION;
    }

    /**
     * Actions whose dates touch the given range at all.
     *
     * Overlap rather than containment: a suspension that started before the
     * cutoff and runs into it still costs days inside it, and a check that
     * only looked for actions *beginning* in the period would miss exactly the
     * long ones that matter most.
     *
     * An open-ended suspension (`effective_to` null) counts as still running,
     * which is the honest reading of "suspended pending investigation".
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(function (Builder $q) use ($from) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from->toDateString());
            });
    }

    public function scopeUnpaidSuspensions(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SUSPENSION)->where('is_unpaid', true);
    }

    /**
     * How many days of this action fall inside the given range.
     *
     * Calendar days, not working days — and that is a limit worth stating
     * rather than papering over. A suspension is served in calendar days as
     * written on the memo, but what it *costs* depends on which of those the
     * person was rostered for, which only `TimekeepingService` knows. This
     * figure is for the warning that tells HR to look; the days that reach a
     * payslip are the ones HR keys.
     */
    public function daysWithin(Carbon $from, Carbon $to): int
    {
        $start = $this->effective_from->greaterThan($from) ? $this->effective_from : $from;
        $end = $this->effective_to === null || $this->effective_to->greaterThan($to)
            ? $to
            : $this->effective_to;

        return $start->greaterThan($end) ? 0 : $start->diffInDays($end) + 1;
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_unpaid' => 'boolean',
        ];
    }
}
