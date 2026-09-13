<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'employee_number' => $this->employee->employee_number,
            ]),

            'date' => $this->date?->toDateString(),
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'hours' => (float) $this->hours,
            'reason' => $this->reason,
            'status' => $this->status,
            'remarks' => $this->remarks,

            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'acted_at' => $this->acted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'can' => [
                'decide' => $viewer?->can('decide', $this->resource) ?? false,
                'cancel' => $viewer?->can('cancel', $this->resource) ?? false,
            ],
        ];
    }
}
