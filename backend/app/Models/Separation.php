<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Separation extends Model
{
    use Auditable, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_RELEASED = 'released';

    public const REASON_RESIGNED = 'resigned';

    public const REASON_TERMINATED = 'terminated';

    public const REASON_END_OF_CONTRACT = 'end_of_contract';

    public const REASON_RETIREMENT = 'retirement';

    public const REASONS = [
        self::REASON_RESIGNED,
        self::REASON_TERMINATED,
        self::REASON_END_OF_CONTRACT,
        self::REASON_RETIREMENT,
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Released settlements are history — figures and clearance are frozen. */
    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_RELEASED;
    }

    /** Every blocking clearance item signed off. */
    public function isCleared(): bool
    {
        return collect($this->clearance ?? [])
            ->where('blocking', true)
            ->every(fn (array $item) => filled($item['cleared_at'] ?? null));
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            )
            ->when(
                $filters['reason'] ?? null,
                fn (Builder $q, $value) => $q->where('reason', $value),
            );
    }

    protected function casts(): array
    {
        return [
            'last_day' => 'date',
            'released_at' => 'datetime',
            'breakdown' => 'array',
            'clearance' => 'array',
            'unpaid_salary' => 'decimal:2',
            'thirteenth_month' => 'decimal:2',
            'leave_conversion' => 'decimal:2',
            'loan_deduction' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net_final_pay' => 'decimal:2',
        ];
    }
}
