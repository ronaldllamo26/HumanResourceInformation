<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Files a batch of scanned 201-file documents against the people they name.
 *
 * The third way a filing cabinet gets digitised. The single-document scanner
 * answers "is this Anastasia's licence?" — the employee is already known,
 * because HR opened her record to upload it. Here nobody has been opened yet:
 * a stack of scans comes off the glass and the question is *whose* each one is.
 *
 * It reuses `DocumentScanner` for the reading and its `nameMatches()` for the
 * comparison rather than re-deriving either. A second implementation of the
 * name rules would be a second place for the married-name and truncated-card
 * cases to be decided, and the two would eventually disagree about the same
 * driver — the same reasoning that has DeploymentReadinessChecker reuse the
 * two scanners instead of judging a lapsed licence itself.
 *
 * **`process()` files what it can defend and hands back the rest.** That is a
 * change from what this class used to do — propose everything and write
 * nothing until a person had touched all forty rows — and the reasoning is
 * about where a person is *spent* rather than about trusting the model more.
 * Retyping forty documents to catch the two that are wrong puts the same
 * attention on the thirty-eight that are right, and attention spread evenly
 * over forty rows is attention nobody is really paying by row thirty. So the
 * system files the ones every check agrees on, and the person opens a screen
 * holding only the exceptions, where their reading is worth something.
 *
 * Every gate is in `config('scanner.autofile')`, every one of them is a
 * failure this scanner has actually produced, and every one of them *holds*
 * rather than refuses — a held document lands in the same review table it
 * always did and is filed by hand exactly as before. Nothing is discarded and
 * nothing is decided that a person cannot see afterwards: an auto-filed row
 * carries `filed_automatically`, so "the system decided this" can always be
 * told from "somebody typed this".
 *
 * `examine()` is still here and still writes nothing — it is what runs with
 * `autofile.enabled` off, and what `process()` calls before deciding.
 */
class BulkDocumentFiler
{
    /**
     * How the match was made, strongest first.
     *
     * The order is the point. An ID number belongs to one person and OCR reads
     * digits well; a name is shared by thousands and arrives truncated. So a
     * number that matches the 201 file settles the question even when the name
     * reading looks wrong — the same precedence the upload form applies.
     */
    public const BY_NUMBER = 'number';

    public const BY_NAME = 'name';

    public const AMBIGUOUS = 'ambiguous';

    public const UNMATCHED = 'unmatched';

    public function __construct(
        private readonly DocumentScanner $scanner,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Reads each file and proposes who it belongs to, writing nothing.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Collection<int, Employee>  $candidates  narrowed by the caller's own scope
     * @return array<int, array<string, mixed>>
     */
    public function examine(array $files, Collection $candidates): array
    {
        return array_values(array_map(
            fn (UploadedFile $file, int $index) => $this->examineOne($file, $index, $candidates),
            $files,
            array_keys($files),
        ));
    }

    /**
     * Reads the batch, files everything that clears every gate, and returns
     * what it could not.
     *
     * The one entry point the batch screen uses. It is `examine()` plus a
     * decision per row, and the decision is deliberately made here rather than
     * in the controller: what may be filed unattended is a rule about
     * documents, and a second copy of it in an HTTP layer would be a second
     * place to loosen it.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Collection<int, Employee>  $candidates  narrowed by the caller's own scope
     * @return array{filed: int, documents: array<int, array<string, mixed>>}
     */
    public function process(array $files, Collection $candidates): array
    {
        $rows = $this->examine($files, $candidates);
        $filed = 0;

        if (! config('scanner.autofile.enabled')) {
            return ['filed' => 0, 'documents' => $rows];
        }

        foreach ($rows as $index => $row) {
            if (! $row['auto']) {
                continue;
            }

            $employee = $candidates->firstWhere('id', $row['employee_id']);

            // Re-found from the scoped collection rather than trusted from the
            // row, so the same list that gated the reading gates the write.
            if ($employee === null) {
                continue;
            }

            $this->store($employee, $row, $files[$index], automatic: true);

            $rows[$index]['filed'] = true;
            $filed++;
        }

        return [
            'filed' => $filed,
            // Only what still needs somebody. A row already filed would be a
            // second chance to file it.
            'documents' => array_values(array_filter($rows, fn ($row) => ! ($row['filed'] ?? false))),
        ];
    }

    /**
     * Writes the assignments a person confirmed.
     *
     * Keyed by the index the review screen showed, so a file whose match was
     * corrected — or cleared — is filed where the person said, not where the
     * scanner guessed. Anything without an employee is skipped rather than
     * filed somewhere plausible.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array{employee_id: int, type: string, title: string, issued_at: ?string, expires_at: ?string}>  $assignments
     * @return array{filed: int, skipped: int}
     */
    public function file(array $files, array $assignments, Collection $allowed): array
    {
        $filed = 0;
        $skipped = 0;

        foreach ($files as $index => $file) {
            $assignment = $assignments[$index] ?? null;
            $employee = $assignment ? $allowed->firstWhere('id', (int) $assignment['employee_id']) : null;

            // The scope is re-checked here, not trusted from the form: the
            // review screen was built from what this user may see, and the
            // request that follows it must be held to the same list.
            if ($employee === null) {
                $skipped++;

                continue;
            }

            $this->store($employee, $assignment, $file, automatic: false);

            $filed++;
        }

        return compact('filed', 'skipped');
    }

    /**
     * Whether this reading may be filed with nobody looking, and why not.
     *
     * Reasons are returned rather than a bare false: "held" with no cause is
     * the batch filer telling somebody to go and find out what it already
     * knows.
     *
     * @param  array<string, mixed>  $row  a row from examineOne()
     * @param  array<string, mixed>|null  $reading
     * @return array{auto: bool, held_for: array<int, string>}
     */
    private function verdict(array $row, ?array $reading, Collection $candidates): array
    {
        $rules = config('scanner.autofile');
        $held = [];

        if (! $rules['enabled']) {
            return ['auto' => false, 'held_for' => []];
        }

        if ($reading === null) {
            return ['auto' => false, 'held_for' => ['The scanner could not read this file.']];
        }

        if ($row['employee_id'] === null) {
            $held[] = $row['matched_by'] === self::AMBIGUOUS
                ? 'More than one employee fits this name.'
                : 'No employee on file matches this document.';
        } elseif (! in_array($row['matched_by'], $rules['match_strengths'], true)) {
            $held[] = 'Matched by name only, and this batch files on a number.';
        }

        if ($rules['require_certain_type'] && ! ($reading['type_certain'] ?? false)) {
            $held[] = $row['type']
                ? 'The type was inferred rather than read — confirm it.'
                : 'The document type could not be decided.';
        }

        /*
         * A name that contradicts the match. Only reached when the owner was
         * found by a *number*, since a name match cannot contradict itself —
         * and it is exactly the case worth holding: a number keyed against the
         * wrong person puts somebody else's licence in this file.
         */
        if ($row['employee_id'] !== null) {
            $employee = $candidates->firstWhere('id', $row['employee_id']);

            if (
                $employee
                && $this->scanner->nameMatches($reading['name_on_document'] ?? null, $employee) === false
                && ! in_array($row['type'], config('scanner.names_may_differ', []), true)
            ) {
                $held[] = 'The name printed on this document is somebody else.';
            }
        }

        $expiring = in_array($row['type'], config('credentials.expiring_types', []), true);

        if ($rules['require_expiry_for_expiring_types'] && $expiring && empty($row['expires_at'])) {
            $held[] = 'This type expires and no expiry date was read.';
        }

        if ($rules['hold_expired'] && ($row['expiry']['state'] ?? null) === 'expired') {
            $held[] = 'This document has already expired.';
        }

        return ['auto' => $held === [], 'held_for' => $held];
    }

    /**
     * The one write.
     *
     * Both paths land here — the person who confirmed a row and the batch that
     * filed itself — so the two cannot come to disagree about what a filed
     * document looks like. `automatic` is the only thing that differs, and it
     * is recorded rather than inferred: "the system decided this" has to stay
     * distinguishable from "somebody typed this" for as long as the row exists.
     *
     * @param  array<string, mixed>  $values
     */
    private function store(
        Employee $employee,
        array $values,
        UploadedFile $file,
        bool $automatic,
    ): void {
        $type = in_array($values['type'] ?? null, EmployeeDocument::TYPES, true)
            ? $values['type']
            : 'other';

        $this->employees->storeDocument($employee, [
            'type' => $type,
            'title' => ($values['title'] ?? null) ?: config("scanner.labels.{$type}", 'Document'),
            'issued_at' => ($values['issued_at'] ?? null) ?: null,
            'expires_at' => ($values['expires_at'] ?? null) ?: null,
            'filed_automatically' => $automatic,
        ], $file);
    }

    /** @param  Collection<int, Employee>  $candidates */
    private function examineOne(UploadedFile $file, int $index, Collection $candidates): array
    {
        $reading = $this->scanner->scan($file);
        $row = $this->describeReading($file, $index, $reading, $candidates);

        // The verdict is attached to every row, including the ones nothing can
        // be done with — a screen that only labelled the filable ones would
        // leave "why not this one?" unanswered for exactly the rows somebody
        // is looking at.
        return $row + $this->verdict($row, $reading, $candidates);
    }

    /**
     * The reading itself, as a row — who it names, what it is, and how well
     * either was established.
     *
     * @param  array<string, mixed>|null  $reading
     * @param  Collection<int, Employee>  $candidates
     * @return array<string, mixed>
     */
    private function describeReading(
        UploadedFile $file,
        int $index,
        ?array $reading,
        Collection $candidates,
    ): array {

        $base = [
            'index' => $index,
            'file_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'scanned' => $reading !== null,
            'type' => $reading['type'] ?? null,
            'title' => $reading['title'] ?? null,
            // The line the type was decided from, for the same reason the
            // single-document panel shows it.
            'heading' => $reading['heading'] ?? null,
            'type_source' => $reading['type_source'] ?? null,
            // Whether the document has already lapsed, so a stack of forty is
            // not a stack of forty chances to file an expired licence quietly.
            'expiry' => $reading['expiry'] ?? null,
            'issued_at' => $reading['issued_at'] ?? null,
            'expires_at' => $reading['expires_at'] ?? null,
            'name_on_document' => $reading['name_on_document'] ?? null,
            'document_number' => $reading['document_number'] ?? null,
        ];

        // A file the scanner cannot read is not a failure — it is a document
        // somebody assigns by hand, exactly as before any of this existed.
        if ($reading === null) {
            return $base + ['employee_id' => null, 'matched_by' => self::UNMATCHED, 'candidates' => []];
        }

        $byNumber = $this->matchOnNumber($reading, $candidates);

        if ($byNumber !== null) {
            return $base + [
                'employee_id' => $byNumber->id,
                'matched_by' => self::BY_NUMBER,
                'candidates' => [$this->describe($byNumber)],
            ];
        }

        $byName = $this->matchOnName($reading['name_on_document'] ?? null, $candidates);

        /*
         * Two people the document could be is not a match. Picking the first
         * would file it under a coin toss, and the whole reason this is a
         * review screen rather than a background job is that the wrong answer
         * here is silent afterwards — nobody goes looking through another
         * person's 201 file for a document that should never have been there.
         */
        if ($byName->count() === 1) {
            return $base + [
                'employee_id' => $byName->first()->id,
                'matched_by' => self::BY_NAME,
                'candidates' => [$this->describe($byName->first())],
            ];
        }

        return $base + [
            'employee_id' => null,
            'matched_by' => $byName->count() > 1 ? self::AMBIGUOUS : self::UNMATCHED,
            'candidates' => $byName->take(5)->map(fn (Employee $e) => $this->describe($e))->values()->all(),
        ];
    }

    /**
     * The strongest evidence available: a number that is already on a 201 file.
     *
     * Compared by containment for the same reason the upload form does it — a
     * scan often carries the caption with the value, and "LICENSE NO.:
     * N01-23-456789" must not read as a different licence.
     *
     * @param  array<string, mixed>  $reading
     * @param  Collection<int, Employee>  $candidates
     */
    private function matchOnNumber(array $reading, Collection $candidates): ?Employee
    {
        $printed = $this->digits($reading['document_number'] ?? null);

        if ($printed === '') {
            return null;
        }

        $matches = $candidates->filter(function (Employee $employee) use ($printed) {
            foreach ([
                $employee->drivers_license_number,
                $employee->sss_number,
                $employee->philhealth_number,
                $employee->pagibig_number,
                $employee->tin,
            ] as $stored) {
                $stored = $this->digits($stored);

                if ($stored !== '' && str_contains($printed, $stored)) {
                    return true;
                }
            }

            return false;
        });

        // One number, two employees means the 201 files themselves disagree.
        // That is a data problem to show, not one to resolve by guessing.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param  Collection<int, Employee>  $candidates */
    private function matchOnName(?string $printed, Collection $candidates): Collection
    {
        if ($printed === null) {
            return collect();
        }

        return $candidates->filter(
            fn (Employee $employee) => $this->scanner->nameMatches($printed, $employee) === true,
        )->values();
    }

    /** @return array<string, mixed> */
    private function describe(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
        ];
    }

    private function digits(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)) ?? '';
    }
}
