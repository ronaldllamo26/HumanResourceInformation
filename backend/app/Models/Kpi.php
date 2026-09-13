<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A measurable goal. Scope is by department, by position, or global when both
 * are null — the library HR builds scorecards from.
 */
class Kpi extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employeeKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class);
    }

    /** KPIs applicable to an employee: global, or matching their dept/position. */
    public function scopeApplicableTo(Builder $query, Employee $employee): Builder
    {
        return $query->where('is_active', true)->where(function (Builder $inner) use ($employee) {
            $inner->where(function (Builder $global) {
                $global->whereNull('department_id')->whereNull('position_id');
            })
                ->orWhere('department_id', $employee->department_id)
                ->orWhere('position_id', $employee->position_id);
        });
    }

    public function scopeText(): string
    {
        return match (true) {
            $this->position_id !== null => 'Position',
            $this->department_id !== null => 'Department',
            default => 'Company-wide',
        };
    }

    protected function casts(): array
    {
        return [
            'default_weight' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
