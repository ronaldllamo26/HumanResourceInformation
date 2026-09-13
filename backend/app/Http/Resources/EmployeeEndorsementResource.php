<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What Core 1 sees when it submits an endorsement or asks after one.
 *
 * The employee number is included once approved, and it is the point of the
 * whole exchange: it is the identifier this system will use for that person
 * from then on, and Core 1 needs it to close its own record. The rest of the
 * employee is not exposed here — salary, department, and government numbers
 * are behind `viewSensitive` on the employee resource, and an endorsement
 * receipt is not a way around it.
 */
class EmployeeEndorsementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'source' => $this->source,

            'name' => $this->fullName(),
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,

            'position_title' => $this->position_title,
            'client_name' => $this->client_name,
            'date_hired' => $this->date_hired?->toDateString(),

            'status' => $this->status,

            // Present only once a decision has been taken, so an absent key is
            // itself the answer to "has anybody looked at this yet".
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'employee_number' => $this->employee->employee_number,
            ] : null),

            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
