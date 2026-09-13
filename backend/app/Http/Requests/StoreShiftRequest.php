<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isHrAdmin();
    }

    public function rules(): array
    {
        $shift = $this->route('shift');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('shifts', 'name')->ignore($shift?->id),
            ],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'is_night_shift' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.different' => 'A shift cannot start and end at the same time.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_night_shift' => $this->boolean('is_night_shift'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
