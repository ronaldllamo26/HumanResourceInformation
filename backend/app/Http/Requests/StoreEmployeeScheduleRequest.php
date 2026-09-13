<?php

namespace App\Http\Requests;

use App\Models\EmployeeSchedule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isHrAdmin();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'shift_id' => ['required', 'exists:shifts,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'days_of_week' => ['required', 'array', 'min:1'],
            // ISO weekdays: 1 = Monday .. 7 = Sunday.
            'days_of_week.*' => ['integer', 'between:1,7'],
        ];
    }

    public function messages(): array
    {
        return [
            'days_of_week.required' => 'Select at least one working day.',
            'days_of_week.min' => 'Select at least one working day.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Two open-ended schedules for the same employee and period would
            // make the shift lookup ambiguous.
            $overlaps = EmployeeSchedule::where('employee_id', $this->input('employee_id'))
                ->when($this->route('schedule'), fn ($query, $existing) => $query->whereKeyNot($existing->id))
                ->where(function ($query) {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $this->input('effective_from'));
                })
                ->when(
                    $this->input('effective_to'),
                    fn ($query, $to) => $query->whereDate('effective_from', '<=', $to),
                )
                ->exists();

            if ($overlaps) {
                $validator->errors()->add(
                    'effective_from',
                    'This employee already has a schedule covering that period.',
                );
            }
        });
    }
}
