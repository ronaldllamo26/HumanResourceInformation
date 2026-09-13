<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'shift' => $this->whenLoaded('shift', fn () => $this->shift ? [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'start_time' => substr((string) $this->shift->start_time, 0, 5),
                'end_time' => substr((string) $this->shift->end_time, 0, 5),
            ] : null),

            'log_date' => $this->log_date?->toDateString(),
            'time_in' => $this->time_in?->format('H:i'),
            'break_out' => $this->break_out?->format('H:i'),
            'break_in' => $this->break_in?->format('H:i'),
            'time_out' => $this->time_out?->format('H:i'),

            'status' => $this->status,
            'source' => $this->source,
            'hours_worked' => (float) $this->hours_worked,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'overtime_minutes' => (int) $this->overtime_minutes,
            'night_diff_minutes' => (int) $this->night_diff_minutes,
            'remarks' => $this->remarks,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
