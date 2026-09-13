<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off amount another system put on a payslip.
 *
 * Fleet's trip allowances and per diems; Supply Chain's deductions for a
 * damaged or lost item. Both are the same shape — an amount, a label, a
 * period, and the name of whoever decided it — so they are one table rather
 * than two.
 *
 * **`Auditable`, and that is not decoration.** This is money arriving from
 * outside the system, and "who added ₱500 to this payslip" has to be
 * answerable from the record rather than from a log file that rotates. The
 * same reason `AttendanceLog` carries it.
 */
class PayrollAdjustment extends Model
{
    use Auditable;

    public const KIND_EARNING = 'earning';

    public const KIND_DEDUCTION = 'deduction';

    public const KINDS = [self::KIND_EARNING, self::KIND_DEDUCTION];

    /**
     * The systems allowed to post one, named rather than left open.
     *
     * An open field would let a mistyped source create a row nothing ever
     * reads and nobody notices — and on this table that is an allowance
     * somebody was promised and never paid.
     */
    public const SOURCES = [
        'fleet',
        'supply_chain',
        'core3',
        'core4',
        'hr',
    ];

    protected $fillable = [
        'employee_id',
        'payroll_period_id',
        'source',
        'reference',
        'kind',
        'label',
        'amount',
        'is_taxable',
        'notes',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function scopeEarnings(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_EARNING);
    }

    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_DEDUCTION);
    }

    /** Where this amount came from, in words, for a payslip line. */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'fleet' => 'Fleet',
            'supply_chain' => 'Supply Chain',
            'core3' => 'Benefits',
            'core4' => 'Admin',
            default => 'HR',
        };
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_taxable' => 'boolean',
        ];
    }
}
