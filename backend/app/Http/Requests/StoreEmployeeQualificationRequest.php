<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmployeeSkill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Education, training, and skill rows on the 201 file.
 *
 * One request for the three because the gate is the same one in every case —
 * editing a qualification is editing the employee record, so it is
 * `EmployeePolicy::update` rather than a permission of its own. The rules
 * differ, and are picked from the route.
 */
class StoreEmployeeQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    public function rules(): array
    {
        return match ($this->kind()) {
            'education' => $this->educationRules(),
            'training' => $this->trainingRules(),
            default => $this->skillRules(),
        };
    }

    public function messages(): array
    {
        return [
            'year_graduated.min' => 'That graduation year looks like a typo.',
            'expires_at.after' => 'A qualification cannot expire before it was completed.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->kind() !== 'skill') {
                return;
            }

            /*
             * "Forklift" and "forklift" are the same skill, and a list holding
             * both is a list nobody can count. Compared case-insensitively
             * here rather than by a unique index, because a database
             * uniqueness rule would compare by the driver's collation — which
             * differs between the Postgres this runs on and the SQLite the
             * tests use.
             */
            $employee = $this->route('employee');
            $name = mb_strtolower(trim((string) $this->input('name')));

            $clash = EmployeeSkill::where('employee_id', $employee->id)
                ->when($this->route('skill'), fn ($query, $existing) => $query->whereKeyNot($existing->id))
                ->get()
                ->contains(fn (EmployeeSkill $skill) => mb_strtolower($skill->name) === $name);

            if ($clash) {
                $validator->errors()->add('name', 'That skill is already on this record.');
            }
        });
    }

    /** Which of the three is being written, taken from the route name. */
    public function kind(): string
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_contains($name, 'education') => 'education',
            str_contains($name, 'training') => 'training',
            default => 'skill',
        };
    }

    /** The employee these rows hang off, resolved by the route. */
    public function employee(): Employee
    {
        return $this->route('employee');
    }

    protected function prepareForValidation(): void
    {
        // A skill typed with a stray space is the same skill; normalising here
        // means the duplicate check above compares what will be stored.
        if ($this->kind() === 'skill' && $this->has('name')) {
            $this->merge([
                'name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name'))),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function educationRules(): array
    {
        return [
            'level' => ['required', Rule::in(array_keys(config('qualifications.education_levels')))],
            'school' => ['required', 'string', 'max:255'],
            // Blank below senior high, where there is no course to name.
            'course' => ['nullable', 'string', 'max:255'],
            'year_graduated' => [
                'nullable',
                'integer',
                'min:'.config('qualifications.earliest_graduation_year'),
                // Next year, not this one: somebody graduating in March is
                // keyed in the December before it.
                'max:'.(now()->year + 1),
            ],
            'honors' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function trainingRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:64'],
            'completed_at' => ['nullable', 'date', 'before_or_equal:today'],
            // Nullable is a real answer — plenty of qualifications never
            // lapse, and a required date would have HR inventing one.
            'expires_at' => ['nullable', 'date', 'after:completed_at'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function skillRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'proficiency' => [
                'nullable',
                Rule::in(array_keys(config('qualifications.proficiency_levels'))),
            ],
        ];
    }
}
