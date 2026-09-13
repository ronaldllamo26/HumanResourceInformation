<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        return [
            // --- Personal ---
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:16'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'civil_status' => ['nullable', Rule::in(['single', 'married', 'widowed', 'separated'])],
            'nationality' => ['nullable', 'string', 'max:255'],
            'religion' => ['nullable', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'max:8'],

            // --- Contact ---
            'email' => ['nullable', 'email', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:32'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'present_address' => ['nullable', 'string', 'max:255'],
            'permanent_address' => ['nullable', 'string', 'max:255'],

            // --- Emergency contact ---
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:64'],
            'emergency_contact_number' => ['nullable', 'string', 'max:32'],

            // --- Government IDs ---
            'sss_number' => ['nullable', 'string', 'max:32'],
            'philhealth_number' => ['nullable', 'string', 'max:32'],
            'pagibig_number' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],

            // --- Employment ---
            'employment_category' => ['required', Rule::in(Employee::CATEGORIES)],

            /*
             * An external employee is deployed somewhere by definition, and an
             * internal one is deployed nowhere. Prohibited rather than merely
             * ignored on internal staff: a stale client_id left behind when
             * someone is brought in-house would keep them in that client's
             * payroll grouping and on that client's headcount, which is a
             * billing error nobody would think to look for.
             */
            'client_id' => [
                Rule::requiredIf(fn () => $this->input('employment_category') === Employee::CATEGORY_EXTERNAL),
                Rule::prohibitedIf(fn () => $this->input('employment_category') !== Employee::CATEGORY_EXTERNAL),
                'nullable',
                'exists:clients,id',
            ],

            // Overrides the client's own region for someone posted elsewhere.
            'wage_region' => ['nullable', Rule::in(array_keys(config('payroll.wage_regions')))],

            'department_id' => ['nullable', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'supervisor_id' => ['nullable', 'exists:employees,id'],
            'employment_status' => ['required', Rule::in(Employee::EMPLOYMENT_STATUSES)],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time'])],
            'date_hired' => ['required', 'date'],
            'date_regularized' => ['nullable', 'date', 'after_or_equal:date_hired'],
            'date_separated' => ['nullable', 'date', 'after_or_equal:date_hired'],
            'separation_reason' => ['nullable', 'string', 'max:1000'],

            // --- Compensation ---
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'pay_frequency' => ['required', Rule::in(['monthly', 'semi_monthly', 'weekly', 'daily'])],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],

            // --- Fleet ---
            'drivers_license_number' => ['nullable', 'string', 'max:32'],
            // Checked against the card's own list rather than left free text —
            // a DL code is the legal ceiling on what somebody may drive, so an
            // invented one is a driver dispatched on a licence that does not
            // cover the vehicle. LicenseVerifier reports the same thing on
            // records that predate the rule.
            'license_dl_codes' => ['nullable', 'string', 'max:32'],
            'license_conditions' => ['nullable', 'string', 'max:32'],
            'license_expiry' => ['nullable', 'date'],

            'status' => ['required', Rule::in(Employee::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:2048'],

            // --- Optional self-service login ---
            'create_user_account' => ['boolean'],
            'user_role' => ['nullable', Rule::in(['hr_staff', 'supervisor', 'employee'])],
        ];
    }

    public function messages(): array
    {
        return [
            'date_regularized.after_or_equal' => 'Regularization date cannot be earlier than the hire date.',
            'date_separated.after_or_equal' => 'Separation date cannot be earlier than the hire date.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // A login needs an address to sign in with.
            if ($this->boolean('create_user_account') && blank($this->input('email'))) {
                $validator->errors()->add('email', 'An email address is required to create a login account.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'create_user_account' => $this->boolean('create_user_account'),
        ]);
    }
}
