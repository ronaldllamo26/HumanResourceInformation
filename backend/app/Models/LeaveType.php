<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    protected function casts(): array
    {
        return [
            'default_credits' => 'decimal:2',
            'is_paid' => 'boolean',
            'requires_attachment' => 'boolean',
            'is_convertible_to_cash' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
