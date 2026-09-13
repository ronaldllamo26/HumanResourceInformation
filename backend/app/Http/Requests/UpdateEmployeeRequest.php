<?php

namespace App\Http\Requests;

class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    public function rules(): array
    {
        $rules = parent::rules();

        // Accounts are provisioned on create only.
        unset($rules['create_user_account'], $rules['user_role']);

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $employee = $this->route('employee');

            if ($this->input('supervisor_id') && (int) $this->input('supervisor_id') === $employee->id) {
                $validator->errors()->add('supervisor_id', 'An employee cannot be their own supervisor.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Intentionally skips the parent's create_user_account normalisation.
    }
}
