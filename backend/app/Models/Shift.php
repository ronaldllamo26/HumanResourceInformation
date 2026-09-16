<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A working pattern: when a day starts and ends, the break, and the grace for lateness. */
class Shift extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    /** A night shift that ends the next morning, e.g. 22:00–06:00. */
    public function crossesMidnight(): bool
    {
        return substr((string) $this->end_time, 0, 5) <= substr((string) $this->start_time, 0, 5);
    }

    /** Scheduled minutes on the job, break excluded. */
    public function scheduledMinutes(): int
    {
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->start_time));
        [$eh, $em] = array_map('intval', explode(':', (string) $this->end_time));

        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        if ($minutes <= 0) {
            $minutes += 24 * 60;
        }

        return max(0, $minutes - (int) $this->break_minutes);
    }

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
