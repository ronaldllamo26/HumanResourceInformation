<?php

namespace App\Http\Requests;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', AttendanceAdjustment::class);
    }

    public function rules(): array
    {
        return [
            'log_date' => ['required', 'date', 'before_or_equal:today'],

            // "HH:MM" — combined with the date by TimekeepingService.
            'requested_time_in' => ['nullable', 'date_format:H:i'],
            'requested_break_out' => ['nullable', 'date_format:H:i'],
            'requested_break_in' => ['nullable', 'date_format:H:i'],
            'requested_time_out' => ['nullable', 'date_format:H:i'],

            'requested_status' => ['nullable', Rule::in(AttendanceLog::STATUSES)],

            // Long enough to be a reason. An approver deciding on "correction"
            // is deciding on nothing.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'log_date.before_or_equal' => 'A time record cannot be dated in the future.',
            'reason.min' => 'Say what was wrong with the day — an approver has to decide on it.',
            'requested_time_in.date_format' => 'Use 24-hour HH:MM, e.g. 08:00.',
            'requested_time_out.date_format' => 'Use 24-hour HH:MM, e.g. 17:30.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $asked = collect([
                'requested_time_in', 'requested_break_out',
                'requested_break_in', 'requested_time_out', 'requested_status',
            ])->contains(fn (string $field) => filled($this->input($field)));

            // A request that asks for nothing still costs somebody a decision.
            if (! $asked) {
                $validator->errors()->add(
                    'requested_time_in',
                    'Say what the day should be corrected to — a time, or a status.',
                );
            }

            /*
             * One open request per day. Two pending corrections for the same
             * Tuesday are two approvals against one row, and the second silently
             * overwrites the first.
             */
            $duplicate = AttendanceAdjustment::where('employee_id', $this->user()->employee?->id)
                ->whereDate('log_date', $this->input('log_date'))
                ->where('status', AttendanceAdjustment::STATUS_PENDING)
                ->when($this->route('adjustment'), fn ($query, $existing) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'log_date',
                    'A correction for this date is already waiting on a decision.',
                );
            }
        });
    }
}
