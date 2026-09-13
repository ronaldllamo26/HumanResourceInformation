<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLoan extends Model
{
    use Auditable, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = ['sss', 'pagibig', 'company', 'salary_advance'];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('outstanding_balance', '>', 0);
    }

    /**
     * The amortisation to withhold this period — never more than the balance,
     * so a final payment cannot overshoot into a negative loan.
     */
    public function amortisationForPeriod(string $payFrequency): float
    {
        $monthly = (float) $this->monthly_amortization;
        $balance = (float) $this->outstanding_balance;

        $due = $payFrequency === 'semi_monthly' ? $monthly / 2 : $monthly;

        return round(min($due, $balance), 2);
    }

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'monthly_amortization' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'start_date' => 'date',
        ];
    }
}
