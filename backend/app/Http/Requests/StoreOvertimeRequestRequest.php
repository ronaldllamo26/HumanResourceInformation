<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', OvertimeRequest::class);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Give a reason an approver can act on.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /*
             * Everybody files their own, HR included.
             *
             * Enforced here rather than only hidden on the form, because the
             * form was never the rule — the picker is gone, and a posted
             * employee_id that is not the filer's is still refused. See
             * OvertimeRequestPolicy::create for why HR lost the exemption.
             */
            $user = $this->user();

            if ((int) $this->input('employee_id') !== $user->employee?->id) {
                $validator->errors()->add('employee_id', 'You can only file overtime for yourself.');

                return;
            }

            if (! Employee::whereKey($this->input('employee_id'))->exists()) {
                return;
            }

            // A duplicate pending request for the same day would double count.
            $duplicate = OvertimeRequest::where('employee_id', $this->input('employee_id'))
                ->whereDate('date', $this->input('date'))
                ->whereIn('status', [OvertimeRequest::STATUS_PENDING, OvertimeRequest::STATUS_APPROVED])
                ->when($this->route('overtimeRequest'), fn ($query, $existing) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('date', 'An overtime request for this date is already open.');
            }
        });
    }
}
