<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'employee_number' => $this->employee->employee_number,
            ]),
            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
                'is_paid' => $this->leaveType->is_paid,
            ]),

            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days_requested' => (float) $this->days_requested,
            'is_half_day' => $this->is_half_day,
            'half_day_period' => $this->half_day_period,
            'reason' => $this->reason,
            'attachment_url' => $this->attachment_path
                ? route('hr.leave.attachment', $this->id)
                : null,

            'status' => $this->status,
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor?->name),
            'supervisor_acted_at' => $this->supervisor_acted_at?->toIso8601String(),
            'supervisor_remarks' => $this->supervisor_remarks,
            'hr' => $this->whenLoaded('hr', fn () => $this->hr?->name),
            'hr_acted_at' => $this->hr_acted_at?->toIso8601String(),
            'hr_remarks' => $this->hr_remarks,

            'created_at' => $this->created_at?->toIso8601String(),

            'can' => [
                // One decision, not two steps. `endorse` and `confirm` are
                // gone: approving is HR's alone, and a supervisor endorsement
                // never decided anything on its own.
                'decide' => $viewer?->can('decide', $this->resource) ?? false,
                'reject' => $viewer?->can('reject', $this->resource) ?? false,
                'cancel' => $viewer?->can('cancel', $this->resource) ?? false,
            ],
        ];
    }
}
