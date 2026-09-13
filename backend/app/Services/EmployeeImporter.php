<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk employee import — the way an existing workforce gets into the system.
 *
 * The directory could already be exported and never read back, so digitising a
 * roster meant keying it in one person at a time. The columns here are the
 * columns `DataExportController` writes, deliberately: export, correct the
 * spreadsheet, import. A round trip that does not round-trip is a trap.
 *
 * Unlike AttendanceImporter this runs twice — once as a **preview** and once
 * to commit. A malformed DTR row is a day someone re-keys; forty employees
 * created by accident are forty records, forty employee numbers burnt, and
 * possibly forty logins. The file is re-read and re-validated on commit, so
 * nothing the browser sends between the two is trusted.
 */
class EmployeeImporter
{
    /**
     * Columns without which a row cannot be created at all.
     *
     * `employment_category` is here rather than defaulted because filing an
     * agency's staff as internal by omission is the specific error that keeps
     * someone off a client's headcount and out of their billing — the same
     * reason the form refuses it. Everything else with a genuine convention
     * gets a default instead, listed in DEFAULTS.
     */
    private const REQUIRED_HEADERS = [
        'last_name', 'first_name', 'date_hired', 'basic_salary',
        'employment_category', 'employment_status',
    ];

    /** Conventions, applied only where the column is absent or the cell blank. */
    private const DEFAULTS = [
        'employment_type' => 'full_time',
        'pay_frequency' => 'semi_monthly',
        'record_status' => 'active',
        'nationality' => 'Filipino',
    ];

    private const MAX_ROWS = 2000;

    /**
     * Header aliases, so a spreadsheet written by a person still lands.
     *
     * The left-hand side is what the export writes once lower-cased and
     * underscored; the right is the field it maps to.
     */
    private const ALIASES = [
        'sss' => 'sss_number',
        'philhealth' => 'philhealth_number',
        'pag-ibig' => 'pagibig_number',
        'pagibig' => 'pagibig_number',
        'mobile' => 'mobile_number',
        'record_status' => 'status',
        'employee_no' => 'employee_number',
        'department_name' => 'department',
        'position_title' => 'position',
    ];

    /**
     * Reads the file and reports what would happen, without writing anything.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>, errors: array<int, string>}
     */
    public function preview(UploadedFile $file): array
    {
        return $this->run($file, commit: false);
    }

    /**
     * Creates every row that a preview would have called ready.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>, errors: array<int, string>}
     */
    public function import(UploadedFile $file): array
    {
        return $this->run($file, commit: true);
    }

    /** @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>, errors: array<int, string>} */
    private function run(UploadedFile $file, bool $commit): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return $this->fail('The file could not be opened.');
        }

        $headers = $this->readHeaders($handle);

        if ($missing = array_diff(self::REQUIRED_HEADERS, $headers)) {
            fclose($handle);

            return $this->fail('Missing required column(s): '.implode(', ', $missing).'.');
        }

        // Resolved once. A 2000-row file must not run 2000 lookups per column.
        $lookups = [
            'departments' => $this->lowerKeyed(Department::pluck('id', 'name')),
            'positions' => $this->lowerKeyed(Position::pluck('id', 'title')),
            'clients' => $this->clientLookup(),
            'numbers' => Employee::withTrashed()->pluck('id', 'employee_number'),
        ];

        // Duplicates *inside the file* are invisible to a database check until
        // the first of them is written, which on a preview never happens.
        $seenNumbers = [];
        $seenEmails = [];

        $rows = [];
        $line = 1;
        $errors = [];

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;

            if ($line - 1 > self::MAX_ROWS) {
                $errors[] = 'Stopped at '.self::MAX_ROWS.' rows — split the file and import again.';
                break;
            }

            if ($this->isBlank($raw)) {
                continue;
            }

            $data = $this->combine($headers, $raw);
            $row = $this->examine($data, $lookups, $seenNumbers, $seenEmails, $line);

            if ($row['employee_number'] !== null) {
                $seenNumbers[mb_strtolower($row['employee_number'])] = $line;
            }

            if (filled($row['attributes']['email'] ?? null)) {
                $seenEmails[mb_strtolower($row['attributes']['email'])] = $line;
            }

            $rows[] = $row;
        }

        fclose($handle);

        if ($commit) {
            $this->create($rows);
        }

        return [
            'rows' => array_map(fn ($row) => $this->present($row), $rows),
            'summary' => [
                'total' => count($rows),
                'ready' => count(array_filter($rows, fn ($r) => $r['status'] !== 'error')),
                'warnings' => count(array_filter($rows, fn ($r) => $r['status'] === 'warning')),
                'errors' => count(array_filter($rows, fn ($r) => $r['status'] === 'error')),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Writes the rows a preview called ready.
     *
     * One transaction: a file half-imported is worse than one not imported,
     * because the second attempt then collides with its own first half.
     * Employee numbers are taken here rather than at examine() time — a
     * preview must not burn a number it may never use.
     */
    private function create(array &$rows): void
    {
        DB::transaction(function () use (&$rows) {
            foreach ($rows as $index => $row) {
                if ($row['status'] === 'error') {
                    continue;
                }

                $attributes = $row['attributes'];
                $attributes['employee_number'] = $row['employee_number'] ?? Employee::nextEmployeeNumber();

                $rows[$index]['created'] = Employee::create($attributes)->id;
                $rows[$index]['employee_number'] = $attributes['employee_number'];
            }
        });
    }

    /**
     * Decides what one row is, without writing it.
     *
     * `error` refuses the row; `warning` still creates it. The split follows
     * the system's own line: a missing government number is reported by
     * OnboardingChecker and does not stop anyone working, so it is a warning —
     * refusing the row would keep the employee out of the system entirely over
     * something Compliance already chases.
     */
    private function examine(array $data, array $lookups, array $seenNumbers, array $seenEmails, int $line): array
    {
        $errors = [];
        $warnings = [];

        $number = $this->value($data, 'employee_number');

        if ($number !== null) {
            if (isset($lookups['numbers'][$number])) {
                $errors[] = "employee number {$number} already exists";
            } elseif (isset($seenNumbers[mb_strtolower($number)])) {
                $errors[] = "employee number {$number} is also on row {$seenNumbers[mb_strtolower($number)]}";
            }
        }

        foreach (['last_name', 'first_name'] as $field) {
            if ($this->value($data, $field) === null) {
                $errors[] = str_replace('_', ' ', $field).' is empty';
            }
        }

        [$hired, $dateWarning] = $this->date($this->value($data, 'date_hired'));

        if ($hired === null) {
            $errors[] = "could not read date hired '".($this->value($data, 'date_hired') ?? '')."'";
        } elseif ($dateWarning !== null) {
            $warnings[] = $dateWarning;
        }

        $salary = $this->money($this->value($data, 'basic_salary'));

        if ($salary === null) {
            $errors[] = "could not read basic salary '".($this->value($data, 'basic_salary') ?? '')."'";
        }

        $category = mb_strtolower((string) $this->value($data, 'employment_category'));

        if (! in_array($category, Employee::CATEGORIES, true)) {
            $errors[] = 'employment category must be one of: '.implode(', ', Employee::CATEGORIES);
        }

        $status = mb_strtolower((string) ($this->value($data, 'employment_status') ?? ''));

        if (! in_array($status, Employee::EMPLOYMENT_STATUSES, true)) {
            $errors[] = 'employment status must be one of: '.implode(', ', Employee::EMPLOYMENT_STATUSES);
        }

        // The agency rule, and the reason it is an error rather than a
        // warning: an external employee with no client is billed to nobody.
        $clientId = null;

        if ($category === Employee::CATEGORY_EXTERNAL) {
            $client = $this->value($data, 'client');
            $clientId = $client === null ? null : ($lookups['clients'][mb_strtolower($client)] ?? null);

            if ($client === null) {
                $errors[] = 'an external employee needs a client';
            } elseif ($clientId === null) {
                $errors[] = "no client named or coded '{$client}'";
            }
        } elseif ($this->value($data, 'client') !== null) {
            // Prohibited, not ignored — see StoreEmployeeRequest.
            $errors[] = 'internal staff cannot be filed against a client';
        }

        $email = $this->value($data, 'email');

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "'{$email}' is not a valid email";
            $email = null;
        } elseif ($email !== null && isset($seenEmails[mb_strtolower($email)])) {
            $errors[] = "email {$email} is also on row {$seenEmails[mb_strtolower($email)]}";
        }

        [$departmentId, $departmentWarning] = $this->resolve($data, 'department', $lookups['departments']);
        [$positionId, $positionWarning] = $this->resolve($data, 'position', $lookups['positions']);

        foreach ([$departmentWarning, $positionWarning] as $warning) {
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $government = [
            'sss_number' => $this->value($data, 'sss_number'),
            'philhealth_number' => $this->value($data, 'philhealth_number'),
            'pagibig_number' => $this->value($data, 'pagibig_number'),
            'tin' => $this->value($data, 'tin'),
        ];

        /*
         * Reported, never refused. OnboardingChecker already treats a missing
         * government number as non-blocking — it does not stop the person
         * working, it stops the company filing for them — and refusing the row
         * would keep the employee out of the system entirely over something
         * Compliance chases at remittance time anyway.
         */
        $labels = [
            'sss_number' => 'SSS',
            'philhealth_number' => 'PhilHealth',
            'pagibig_number' => 'Pag-IBIG',
            'tin' => 'TIN',
        ];

        $missing = array_keys(array_filter($government, fn ($value) => $value === null));

        if ($missing !== []) {
            $warnings[] = 'no '.implode(', ', array_map(fn ($key) => $labels[$key], $missing));
        }

        $attributes = [
            'last_name' => $this->value($data, 'last_name'),
            'first_name' => $this->value($data, 'first_name'),
            'middle_name' => $this->value($data, 'middle_name'),
            'suffix' => $this->value($data, 'suffix'),
            'email' => $email,
            'mobile_number' => $this->value($data, 'mobile_number'),
            'nationality' => $this->value($data, 'nationality') ?? self::DEFAULTS['nationality'],
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'client_id' => $clientId,
            'employment_category' => $category,
            'employment_status' => $status,
            'employment_type' => $this->enum($data, 'employment_type', ['full_time', 'part_time']),
            'pay_frequency' => $this->enum($data, 'pay_frequency', ['monthly', 'semi_monthly', 'weekly', 'daily']),
            'status' => $this->enum($data, 'status', Employee::STATUSES),
            'date_hired' => $hired,
            'basic_salary' => $salary,
            ...$government,
        ];

        return [
            'line' => $line,
            'employee_number' => $number,
            'name' => trim(($this->value($data, 'last_name') ?? '?').', '.($this->value($data, 'first_name') ?? '?')),
            'attributes' => $attributes,
            'status' => $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ready'),
            'messages' => $errors !== [] ? $errors : $warnings,
            'created' => null,
        ];
    }

    /**
     * A department or position named in the file but not in the system.
     *
     * A warning, not an error: master data is maintained on its own screens by
     * people with the `manageOrganization` gate, and inventing rows from a
     * spreadsheet would let an import quietly reshape the org chart. The
     * employee is created unfiled and HR sets it afterwards.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolve(array $data, string $field, array $lookup): array
    {
        $name = $this->value($data, $field);

        if ($name === null) {
            return [null, null];
        }

        $id = $lookup[mb_strtolower($name)] ?? null;

        return [$id, $id === null ? "no {$field} named '{$name}' — left unfiled" : null];
    }

    /** Clients answer to either their name or their code, as people write both. */
    private function clientLookup(): array
    {
        $lookup = [];

        foreach (Client::get(['id', 'name', 'code']) as $client) {
            $lookup[mb_strtolower($client->name)] = $client->id;

            if (filled($client->code)) {
                $lookup[mb_strtolower($client->code)] = $client->id;
            }
        }

        return $lookup;
    }

    /** The row as the screen shows it — never the raw attributes. */
    private function present(array $row): array
    {
        return [
            'line' => $row['line'],
            'employee_number' => $row['employee_number'],
            'name' => $row['name'],
            'status' => $row['status'],
            'messages' => $row['messages'],
            'created' => $row['created'],
        ];
    }

    private function enum(array $data, string $field, array $allowed): string
    {
        $value = mb_strtolower((string) $this->value($data, $field));

        return in_array($value, $allowed, true)
            ? $value
            : (self::DEFAULTS[$field] ?? self::DEFAULTS['record_status']);
    }

    private function value(array $data, ?string $field): ?string
    {
        if ($field === null) {
            return null;
        }

        $value = trim((string) ($data[$field] ?? ''));

        return $value === '' ? null : $value;
    }

    /** Accepts what a spreadsheet writes: 25,000.00 and PHP 25000 alike. */
    private function money(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $value);

        return is_numeric($clean) && (float) $clean >= 0 ? (float) $clean : null;
    }

    /**
     * A date as a Philippine spreadsheet writes it.
     *
     * `Carbon::parse()` alone is wrong here. It reads a slashed date
     * month-first, so "15/02/2026" throws and "05/02/2026" silently becomes
     * 2 May — a hire date six weeks out of place, which then moves the
     * regularisation window, the 13th-month proration, and the first payslip.
     *
     * So: ISO is taken as written. A slashed date whose first number is over
     * 12 can only be day-first, and one whose *second* number is over 12 can
     * only be month-first — neither needs a guess. What is left is genuinely
     * ambiguous, and rather than pick silently it is read day-first (the local
     * convention) and *reported*, so the one case that can be wrong is the one
     * case somebody is asked to look at.
     *
     * @return array{0: string|null, 1: string|null} the date, and a warning
     */
    private function date(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $value = trim($value);

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value)) {
            return [$this->carbon($value), null];
        }

        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $matches)) {
            [, $first, $second, $year] = array_map('intval', $matches);

            $ambiguous = $first <= 12 && $second <= 12;

            // Day-first unless the numbers say it cannot be.
            [$day, $month] = $second > 12 ? [$second, $first] : [$first, $second];

            if (! checkdate($month, $day, $year)) {
                return [null, null];
            }

            return [
                sprintf('%04d-%02d-%02d', $year, $month, $day),
                $ambiguous
                    ? "read {$value} as ".Carbon::create($year, $month, $day)->format('j F Y')
                        .' — use YYYY-MM-DD if that is wrong'
                    : null,
            ];
        }

        // "15 Feb 2026", "February 15, 2026" and friends carry their own month.
        return [$this->carbon($value), null];
    }

    private function carbon(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, string> */
    private function readHeaders($handle): array
    {
        $headers = fgetcsv($handle) ?: [];

        $headers = array_map(
            // Tolerates "Employee Number", "employee_number", and a UTF-8 BOM.
            fn ($header) => str_replace(' ', '_', mb_strtolower(trim((string) $header, " \t\n\r\0\x0B\u{FEFF}"))),
            $headers,
        );

        return array_map(fn ($header) => self::ALIASES[$header] ?? $header, $headers);
    }

    /** @return array<string, string> */
    private function combine(array $headers, array $row): array
    {
        $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');

        return array_map(fn ($value) => trim((string) $value), array_combine($headers, $row));
    }

    private function isBlank(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }

    /** @return array<string, int|string> */
    private function lowerKeyed($collection): array
    {
        $out = [];

        foreach ($collection as $key => $id) {
            $out[mb_strtolower((string) $key)] = $id;
        }

        return $out;
    }

    private function fail(string $message): array
    {
        return [
            'rows' => [],
            'summary' => ['total' => 0, 'ready' => 0, 'warnings' => 0, 'errors' => 0],
            'errors' => [$message],
        ];
    }
}
