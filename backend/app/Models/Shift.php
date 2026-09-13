<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    /** A shift whose end time is at or before its start time runs past midnight. */
    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'is_night_shift' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
