<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which employees are not fully on file yet.
 *
 * The Compliance screen already catches a missing government number — but it
 * catches it at remittance time, when the filing is due and the employee is
 * unreachable on a route somewhere. This asks the same question at the point
 * it is still cheap to answer: what is missing from this person's 201 file?
 *
 * Config-driven and database-free, the same shape as CredentialExpiryScanner:
 * `config/onboarding.php` says what a complete file holds, so a new client
 * audit demanding another document is a config edit.
 *
 * Requirements are per position, not global. A dispatcher does not need a
 * driver's licence; a driver may not legally work without one, so the same
 * "blocking" distinction the credential screen draws applies here.
 */
class OnboardingChecker
{
    /**
     * @return Collection<int, array<string, mixed>> incomplete files only,
     *                                               blocking gaps first
     */
    public function scan(Builder $query): Collection
    {
        $employees = (clone $query)->reorder()
            ->with(['documents:id,employee_id,type', 'position:id,title', 'department:id,name'])
            ->get();

        return $employees
            ->map(fn (Employee $employee) => $this->evaluate($employee))
            ->filter()
            ->sortBy([
                fn (array $row) => $row['blocking'] > 0 ? 0 : 1,
                fn (array $row) => -$row['missing_count'],
                fn (array $row) => $row['employee_name'],
            ])
            ->values();
    }

    /** The count for a summary tile, without building the rows. */
    public function countIncomplete(Builder $query): int
    {
        return $this->scan($query)->count();
    }

    /**
     * Null when nothing is missing — a complete file is not a finding, and
     * listing every compliant employee would bury the ones that aren't.
     *
     * @return array<string, mixed>|null
     */
    private function evaluate(Employee $employee): ?array
    {
        $held = $employee->documents->pluck('type')->unique();
        $missing = [];

        foreach ($this->requirementsFor($employee) as $type => $rule) {
            if (! $held->contains($type)) {
                $missing[] = [
                    'kind' => 'document',
                    'key' => $type,
                    'label' => $rule['label'],
                    'blocking' => (bool) $rule['blocking'],
                ];
            }
        }

        foreach (config('onboarding.government_numbers', []) as $field => $label) {
            if (blank($employee->{$field})) {
                $missing[] = [
                    'kind' => 'government_number',
                    'key' => $field,
                    'label' => $label,
                    // A missing number doesn't stop the person working; it
                    // stops the company filing for them.
                    'blocking' => false,
                ];
            }
        }

        if ($missing === []) {
            return null;
        }

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'position' => $employee->position?->title,
            'department' => $employee->department?->name,
            'date_hired' => $employee->date_hired?->toDateString(),
            'missing' => $missing,
            'missing_count' => count($missing),
            'blocking' => collect($missing)->where('blocking', true)->count(),
        ];
    }

    /**
     * Global requirements plus anything this employee's position adds.
     *
     * @return array<string, array{label: string, blocking: bool}>
     */
    private function requirementsFor(Employee $employee): array
    {
        $required = config('onboarding.documents', []);
        $title = strtolower((string) $employee->position?->title);

        foreach (config('onboarding.by_position', []) as $fragment => $extra) {
            if ($title !== '' && str_contains($title, strtolower($fragment))) {
                $required = array_merge($required, $extra);
            }
        }

        return $required;
    }
}
