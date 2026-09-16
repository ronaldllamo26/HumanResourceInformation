<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One deployed employee's totals on a client timesheet, snapshotted when it was prepared. */
class ClientTimesheetLine extends Model
{
    protected $guarded = ['id'];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(ClientTimesheet::class, 'client_timesheet_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'days_worked' => 'decimal:2',
            'hours_worked' => 'decimal:2',
            'absent_days' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }
}
