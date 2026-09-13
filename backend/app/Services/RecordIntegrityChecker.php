<?php

namespace App\Services;

use App\Models\DocumentScan;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where the records disagree with each other.
 *
 * The other two checkers ask about one file at a time: `OnboardingChecker`
 * asks what is *missing*, `CredentialExpiryScanner` asks what is *lapsing*.
 * Neither can see a number keyed against two people, or a licence filed twice,
 * or a scanned document that named somebody the 201 file does not.
 *
 * Config-driven and database-free like both of them — `config/integrity.php`
 * holds the formats and the rules, so a change to a government number's length
 * is a config edit.
 *
 * **There is no model in here, and that is the point.** Every finding is a
 * regex or a string comparison: a TIN with eleven digits is wrong for a reason
 * that can be written down, and a rule that can be written down should not be
 * inferred by something that might hallucinate it. The one comparison that is
 * not trivial — whether two names are the same person — is
 * `DocumentScanner::nameMatches()`, reused rather than restated, so this
 * screen and the upload form cannot disagree about the same employee.
 *
 * Nothing here blocks anything. It is a work queue.
 */
class RecordIntegrityChecker
{
    /** Ordered worst first: a shared number outranks a formatting slip. */
    public const SEVERITY = ['error' => 0, 'warning' => 1];

    public function __construct(
        private readonly DocumentScanner $scanner,
        private readonly LicenseVerifier $licenses,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>> employees with findings only
     */
    public function scan(Builder $query): Collection
    {
        $employees = (clone $query)->reorder()
            ->with(['documents:id,employee_id,type,title,issued_at,expires_at', 'position:id,title'])
            ->get();

        // Resolved once over the whole set. Asking "does anyone else hold this
        // number" per employee would be one query each, and the answer needs
        // every employee anyway — including the ones this user cannot see,
        // because a collision with a record outside their scope is still a
        // collision.
        $shared = $this->sharedNumbers();
        $scans = $this->scansByEmployee($employees->pluck('id'));

        return $employees
            ->map(fn (Employee $employee) => $this->evaluate($employee, $shared, $scans))
            ->filter()
            ->sortBy([
                fn (array $row) => self::SEVERITY[$row['severity']],
                fn (array $row) => -count($row['findings']),
                fn (array $row) => $row['employee_name'],
            ])
            ->values();
    }

    /** The count for a summary tile, without building the rows. */
    public function countAffected(Builder $query): int
    {
        return $this->scan($query)->count();
    }

    /**
     * Null when a record agrees with itself and with everyone else — a clean
     * file is not a finding, and listing every compliant employee would bury
     * the ones that are not.
     *
     * @param  array<string, array<string, array<int, string>>>  $shared
     * @param  Collection<int, Collection<int, DocumentScan>>  $scans
     * @return array<string, mixed>|null
     */
    private function evaluate(Employee $employee, array $shared, Collection $scans): ?array
    {
        $findings = [
            ...$this->numberFormats($employee),
            ...$this->duplicateNumbers($employee, $shared),
            ...$this->duplicateDocuments($employee),
            ...$this->documentDates($employee),
            ...$this->scannedNames($employee, $scans->get($employee->id) ?? collect()),
            ...$this->licence($employee),
        ];

        if ($findings === []) {
            return null;
        }

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'position' => $employee->position?->title,
            'severity' => collect($findings)->contains(fn ($f) => $f['severity'] === 'error')
                ? 'error'
                : 'warning',
            'findings' => $findings,
        ];
    }

    /**
     * A government number that is not the shape its agency issues.
     *
     * A warning, never an error: an employee may hold a card issued under an
     * older format, and a keying slip is a correction rather than a conflict.
     * What it is not is a reason to doubt the person.
     *
     * @return array<int, array<string, mixed>>
     */
    private function numberFormats(Employee $employee): array
    {
        $findings = [];

        foreach (config('integrity.number_formats', []) as $field => $rule) {
            $value = $employee->{$field};

            if (blank($value)) {
                // Absent is OnboardingChecker's finding, not this one — saying
                // it twice on two screens teaches people to ignore both.
                continue;
            }

            if ($this->fits($value, $rule['patterns'])) {
                continue;
            }

            $findings[] = [
                'type' => 'number_format',
                'severity' => 'warning',
                'field' => $field,
                'summary' => "{$rule['label']} number is not the usual shape",
                'detail' => "{$value} — expected {$rule['shape']}.",
            ];
        }

        return $findings;
    }

    /**
     * The same government number on two people.
     *
     * An error, and the one finding here that is about a pair of records
     * rather than one. It is what a copied spreadsheet row looks like, and
     * what a number keyed against the wrong person looks like — and left
     * alone it puts one employee's contributions under another's name at
     * remittance time.
     *
     * @param  array<string, array<string, array<int, string>>>  $shared
     * @return array<int, array<string, mixed>>
     */
    private function duplicateNumbers(Employee $employee, array $shared): array
    {
        $findings = [];

        foreach (config('integrity.unique_numbers', []) as $field) {
            $value = $this->digits($employee->{$field} ?? null);

            if ($value === '' || ! isset($shared[$field][$value])) {
                continue;
            }

            $others = array_values(array_diff($shared[$field][$value], [$employee->employee_number]));

            if ($others === []) {
                continue;
            }

            $label = config("integrity.number_formats.{$field}.label", $field);

            $findings[] = [
                'type' => 'duplicate_number',
                'severity' => 'error',
                'field' => $field,
                'summary' => "{$label} number is also on another record",
                'detail' => 'Shared with '.implode(', ', $others).'. One of them is wrong.',
            ];
        }

        return $findings;
    }

    /**
     * Two current copies of a document a person holds one of.
     *
     * Only the types in `single_copy_types`. An employee accumulates
     * clearances and certificates legitimately — a fresh NBI clearance every
     * year — and flagging those would bury the finding that matters.
     *
     * A superseded copy is not a duplicate either: two licences where one has
     * already expired is a renewal with its history kept, which is correct.
     *
     * @return array<int, array<string, mixed>>
     */
    private function duplicateDocuments(Employee $employee): array
    {
        $findings = [];
        $today = now()->toDateString();

        foreach (config('integrity.single_copy_types', []) as $type) {
            $current = $employee->documents
                ->where('type', $type)
                ->filter(fn (EmployeeDocument $doc) => $doc->expires_at === null
                    || $doc->expires_at->toDateString() >= $today);

            if ($current->count() < 2) {
                continue;
            }

            $findings[] = [
                'type' => 'duplicate_document',
                'severity' => 'warning',
                'field' => $type,
                'summary' => $current->count()." current copies of one {$this->label($type)}",
                'detail' => 'An employee holds one at a time. Remove the superseded copy, or set its expiry.',
            ];
        }

        return $findings;
    }

    /**
     * Dates that cannot both be true.
     *
     * A document issued before the person was born is a misread year or a
     * document belonging to somebody else, and either is worth a look.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentDates(Employee $employee): array
    {
        if ($employee->birth_date === null) {
            return [];
        }

        $findings = [];

        foreach ($employee->documents as $document) {
            if ($document->issued_at === null) {
                continue;
            }

            if ($document->issued_at->greaterThanOrEqualTo($employee->birth_date)) {
                continue;
            }

            $findings[] = [
                'type' => 'date_conflict',
                'severity' => 'error',
                'field' => $document->type,
                'summary' => "{$this->label($document->type)} is dated before the employee was born",
                'detail' => 'Issued '.$document->issued_at->toDateString()
                    .', born '.$employee->birth_date->toDateString().'.',
            ];
        }

        return $findings;
    }

    /**
     * A scanned document that named somebody the 201 file does not.
     *
     * Reads what the scanner recorded rather than re-reading the file: the
     * proposal is already stored for the accuracy figures, so this costs a
     * query rather than a model call.
     *
     * The upload form refuses a mismatch outright, so anything landing here
     * arrived before that check existed, through the batch filer, or on a type
     * where a different name is expected — which is why a PSA is skipped.
     *
     * @param  Collection<int, DocumentScan>  $scans
     * @return array<int, array<string, mixed>>
     */
    private function scannedNames(Employee $employee, Collection $scans): array
    {
        if (! config('integrity.report_name_mismatch', true)) {
            return [];
        }

        $exempt = config('scanner.names_may_differ', []);
        $findings = [];
        $seen = [];

        foreach ($scans as $scan) {
            $type = $scan->saved['type'] ?? $scan->proposed['type'] ?? null;
            $name = $scan->proposed['name_on_document'] ?? null;

            if ($name === null || in_array($type, $exempt, true)) {
                continue;
            }

            // One finding per name, however many documents carried it.
            if (isset($seen[$name]) || $this->scanner->nameMatches($name, $employee) !== false) {
                continue;
            }

            $seen[$name] = true;

            $findings[] = [
                'type' => 'name_mismatch',
                'severity' => 'error',
                'field' => $type,
                'summary' => 'A filed document names somebody else',
                'detail' => "Read as \"{$name}\" on a {$this->label($type)}.",
            ];
        }

        return $findings;
    }

    /**
     * Every government number that more than one employee holds, keyed by
     * field and by the normalised number.
     *
     * Built over the whole table on purpose. A collision with a record the
     * current user cannot see is still a collision, and hiding it would let
     * the duplicate survive precisely because of who was looking.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function sharedNumbers(): array
    {
        $fields = config('integrity.unique_numbers', []);

        $rows = Employee::withTrashed()
            ->get(array_merge(['id', 'employee_number'], $fields));

        $index = [];

        foreach ($rows as $employee) {
            foreach ($fields as $field) {
                $value = $this->digits($employee->{$field} ?? null);

                if ($value === '') {
                    continue;
                }

                $index[$field][$value][] = $employee->employee_number;
            }
        }

        // Keep only the numbers held more than once.
        foreach ($index as $field => $numbers) {
            $index[$field] = array_filter($numbers, fn (array $holders) => count(array_unique($holders)) > 1);
        }

        return $index;
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     * @return Collection<int, Collection<int, DocumentScan>>
     */
    private function scansByEmployee(Collection $employeeIds): Collection
    {
        return DocumentScan::whereIn('employee_id', $employeeIds)
            ->get(['id', 'employee_id', 'proposed', 'saved'])
            ->groupBy('employee_id');
    }

    /** @param  array<int, string>  $patterns */
    private function fits(string $value, array $patterns): bool
    {
        $clean = $this->digits($value);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clean) === 1) {
                return true;
            }
        }

        return false;
    }

    private function digits(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)) ?? '';
    }

    private function label(?string $type): string
    {
        return config("scanner.labels.{$type}", 'document');
    }

    /**
     * What the licence says about itself.
     *
     * Delegated to `LicenseVerifier` rather than restated here, for the same
     * reason `scannedNames()` reuses `DocumentScanner::nameMatches()`: the
     * employee's own screen and this one must not disagree about the same
     * card. This screen is where a driver whose codes predate the current
     * scheme surfaces — the retired numeric restrictions read as codes LTO
     * does not issue, which is exactly what they are.
     *
     * @return array<int, array>
     */
    private function licence(Employee $employee): array
    {
        return collect($this->licenses->check($employee))
            ->map(fn (array $finding) => [
                'type' => 'licence',
                'severity' => $finding['severity'],
                'field' => $finding['field'],
                'summary' => $finding['summary'],
                'detail' => $finding['detail'],
            ])
            ->all();
    }
}
