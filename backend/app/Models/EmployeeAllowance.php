<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmployeeAllowance extends Model
{
    use Auditable, HasFactory;

    public const FREQUENCIES = ['monthly', 'semi_monthly', 'per_payroll'];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Allowances in force on the given date. */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date) {
                $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });
    }

    /**
     * What this allowance contributes to a single payroll period.
     * A monthly allowance is halved on a semi-monthly run.
     */
    public function amountForPeriod(string $payFrequency): float
    {
        $amount = (float) $this->amount;

        if ($this->frequency === 'per_payroll') {
            return round($amount, 2);
        }

        if ($this->frequency === 'monthly' && $payFrequency === 'semi_monthly') {
            return round($amount / 2, 2);
        }

        if ($this->frequency === 'semi_monthly' && $payFrequency === 'monthly') {
            return round($amount * 2, 2);
        }

        return round($amount, 2);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
