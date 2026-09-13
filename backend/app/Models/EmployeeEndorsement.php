<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hire proposed by Core 1, awaiting a decision here.
 *
 * Core 1 recruits and Core 2 employs, so nobody reaches this system's payroll
 * without somebody here saying yes. The row is the record of that handover: it
 * keeps what was sent, who decided, when, and — on a rejection — why.
 *
 * Nothing here is ever deleted. A declined endorsement is the answer to "did
 * we get this person and what did we do about them", which is a question that
 * outlives the decision; and Core 1 reads the outcome back from this row.
 */
class EmployeeEndorsement extends Model
{
    use Auditable;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $guarded = ['id'];

    /*
     * -----------------------------------------------------------------
     * Relations
     * -----------------------------------------------------------------
     */

    /**
     * The record this became, once approved.
     *
     * `withTrashed()` for the same reason `Payslip::employee()` carries it: an
     * approved endorsement is the evidence of how somebody entered the system,
     * and archiving them later must not erase what it was evidence *of*.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /*
     * -----------------------------------------------------------------
     * Scopes
     * -----------------------------------------------------------------
     */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    /** Matches name, reference, or email — the same shape as Client::scopeSearch. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        // `ilike` is Postgres-only; sqlite's LIKE is already case-insensitive.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(fn (Builder $inner) => $inner
            ->where('first_name', $operator, $like)
            ->orWhere('last_name', $operator, $like)
            ->orWhere('reference', $operator, $like)
            ->orWhere('email', $operator, $like),
        );
    }

    /*
     * -----------------------------------------------------------------
     * Reads
     * -----------------------------------------------------------------
     */

    public function fullName(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])
            ->filter()
            ->join(' ');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'date_hired' => 'date',
            'decided_at' => 'datetime',
        ];
    }
}
