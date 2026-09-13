<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company PrimePower deploys employees to.
 *
 * The agency's external workforce is filed against one of these, which is what
 * lets the directory, payroll, and every per-client report be separated. An
 * internal staff member has no client.
 */
class Client extends Model
{
    use Auditable;

    // A deleted client keeps its row: payslips and attendance are grouped by
    // client_id, and a hard delete would leave that history pointing nowhere.
    use SoftDeletes;

    protected $guarded = ['id'];

    /** Matches name or code, the same shape as Department::scopeSearch. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        // `ilike` is Postgres-only; sqlite's LIKE is already case-insensitive.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(fn (Builder $inner) => $inner
            ->where('name', $operator, $like)
            ->orWhere('code', $operator, $like),
        );
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Deployed headcount — the figure a client actually asks about. */
    public function activeEmployees(): HasMany
    {
        return $this->employees()->where('status', 'active');
    }

    /**
     * Whether the service agreement has lapsed.
     *
     * Reported, never enforced: a contract past its end date with people still
     * deployed is exactly the situation worth seeing on a screen, and blocking
     * payroll over it would strand those employees unpaid.
     */
    public function contractHasLapsed(): bool
    {
        return $this->contract_end !== null && $this->contract_end->isPast();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'contract_start' => 'date',
            'contract_end' => 'date',
        ];
    }
}
