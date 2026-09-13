<?php

namespace App\Http\Requests;

use App\Models\AttendanceLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AttendanceLog::class);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'shift_id' => ['nullable', 'exists:shifts,id'],

            // "HH:MM" — combined with log_date by TimekeepingService.
            'time_in' => ['nullable', 'date_format:H:i'],
            'break_out' => ['nullable', 'date_format:H:i'],
            'break_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],

            'status' => ['nullable', Rule::in(AttendanceLog::STATUSES)],
            'source' => ['nullable', Rule::in(AttendanceLog::SOURCES)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'log_date.before_or_equal' => 'A time record cannot be dated in the future.',
            'time_in.date_format' => 'Use 24-hour HH:MM, e.g. 08:00.',
            'time_out.date_format' => 'Use 24-hour HH:MM, e.g. 17:30.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // A time-out with no time-in cannot be interpreted.
            if (filled($this->input('time_out')) && blank($this->input('time_in'))) {
                $validator->errors()->add('time_in', 'A time-in is required when a time-out is recorded.');
            }

            if (filled($this->input('break_in')) && blank($this->input('break_out'))) {
                $validator->errors()->add('break_out', 'A break-out is required when a break-in is recorded.');
            }
        });
    }
}
