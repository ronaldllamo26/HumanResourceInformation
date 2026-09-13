<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** What the employee can still file against this year. */
    public function available(): float
    {
        return round(
            (float) $this->credits_earned
            + (float) $this->credits_carried_over
            - (float) $this->credits_used,
            2,
        );
    }

    protected function casts(): array
    {
        return [
            'credits_earned' => 'decimal:2',
            'credits_used' => 'decimal:2',
            'credits_carried_over' => 'decimal:2',
        ];
    }
}
