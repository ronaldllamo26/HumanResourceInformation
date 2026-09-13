<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LeaveRequest::class);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day' => ['boolean'],
            'half_day_period' => ['nullable', Rule::in(['morning', 'afternoon'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Give a reason an approver can act on.',
            'end_date.after_or_equal' => 'The end date cannot fall before the start date.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            $employee = Employee::find($this->input('employee_id'));
            $type = LeaveType::find($this->input('leave_type_id'));

            if (! $employee || ! $type) {
                return;
            }

            /*
             * Everybody files their own, HR included.
             *
             * The exemption that used to sit here — `! $user->isHrAdmin() &&`
             * — let HR file on anybody's behalf, which made HR both the filer
             * and the sole approver of the same request. That is the one thing
             * the rest of this module is built to prevent, and it survived
             * removing the button because the button was never the rule.
             */
            if ($employee->user_id !== $user->id) {
                $validator->errors()->add('employee_id', 'You can only file leave for yourself.');

                return;
            }

            if (! $type->is_active) {
                $validator->errors()->add('leave_type_id', 'That leave type is no longer available.');
            }

            $start = Carbon::parse($this->input('start_date'));
            $end = Carbon::parse($this->input('end_date'));

            if ($this->boolean('is_half_day') && ! $start->isSameDay($end)) {
                $validator->errors()->add('end_date', 'A half day must start and end on the same date.');
            }

            if ($type->requires_attachment && ! $this->hasFile('attachment')) {
                $validator->errors()->add('attachment', "{$type->name} requires a supporting document.");
            }

            if ($type->min_days_notice > 0 && $start->lt(now()->addDays($type->min_days_notice)->startOfDay())) {
                $validator->errors()->add(
                    'start_date',
                    "{$type->name} must be filed at least {$type->min_days_notice} day(s) in advance.",
                );
            }

            $this->checkOverlap($validator, $employee);
            $this->checkBalance($validator, $employee, $type, $start, $end);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_half_day' => $this->boolean('is_half_day')]);
    }

    /** A second request over the same dates would double-book the calendar. */
    private function checkOverlap($validator, Employee $employee): void
    {
        $overlaps = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', LeaveRequest::OPEN_STATUSES)
            ->when($this->route('leaveRequest'), fn ($query, $existing) => $query->whereKeyNot($existing->id))
            ->overlapping($this->input('start_date'), $this->input('end_date'))
            ->exists();

        if ($overlaps) {
            $validator->errors()->add('start_date', 'This employee already has leave filed over these dates.');
        }
    }

    private function checkBalance($validator, Employee $employee, LeaveType $type, Carbon $start, Carbon $end): void
    {
        if (! $type->is_paid) {
            return;
        }

        $service = app(LeaveService::class);
        $requested = $service->workingDays($employee, $start, $end, $this->boolean('is_half_day'));

        if ($requested <= 0) {
            $validator->errors()->add(
                'start_date',
                'That range contains no working days — it is all rest days or holidays.',
            );

            return;
        }

        $available = $service->availableCredits($employee, $type, $start->year);

        if ($requested > $available) {
            $validator->errors()->add(
                'leave_type_id',
                "Only {$available} day(s) of {$type->name} remain; {$requested} were requested.",
            );
        }
    }
}
