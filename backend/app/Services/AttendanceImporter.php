<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Bulk DTR import — the integration hook for biometric device exports.
 *
 * Every row goes through TimekeepingService::record(), so an imported day is
 * computed exactly like a hand-keyed one. Bad rows are reported rather than
 * aborting the batch: a device export with one malformed line should still
 * import the other 500.
 */
class AttendanceImporter
{
    private const REQUIRED_HEADERS = ['employee_number', 'date'];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly TimekeepingService $timekeeping) {}

    /**
     * @return array{imported: int, failed: int, errors: array<int, string>}
     */
    public function import(UploadedFile $file, string $source = 'import'): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['imported' => 0, 'failed' => 0, 'errors' => ['The file could not be opened.']];
        }

        $headers = $this->readHeaders($handle);

        if ($missing = array_diff(self::REQUIRED_HEADERS, $headers)) {
            fclose($handle);

            return [
                'imported' => 0,
                'failed' => 0,
                'errors' => ['Missing required column(s): '.implode(', ', $missing).'.'],
            ];
        }

        // Resolved once so a 5000-row file does not run 5000 lookups.
        $employees = Employee::pluck('id', 'employee_number');

        $imported = 0;
        $failed = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($line - 1 > self::MAX_ROWS) {
                $errors[] = 'Stopped at '.self::MAX_ROWS.' rows — split the file and import again.';
                break;
            }

            if ($this->isBlank($row)) {
                continue;
            }

            $data = $this->combine($headers, $row);
            $error = $this->validate($data, $employees);

            if ($error !== null) {
                $failed++;
                // Keep the report readable on a badly formed file.
                if (count($errors) < 25) {
                    $errors[] = "Row {$line}: {$error}";
                }

                continue;
            }

            $this->timekeeping->record(
                Employee::find($employees[$data['employee_number']]),
                [
                    'log_date' => Carbon::parse($data['date'])->toDateString(),
                    'time_in' => $this->time($data['time_in'] ?? null),
                    'time_out' => $this->time($data['time_out'] ?? null),
                    'break_out' => $this->time($data['break_out'] ?? null),
                    'break_in' => $this->time($data['break_in'] ?? null),
                    'source' => $source,
                    'biometric_device_id' => $data['device_id'] ?? null,
                ],
            );

            $imported++;
        }

        fclose($handle);

        return compact('imported', 'failed', 'errors');
    }

    /** @return array<int, string> */
    private function readHeaders($handle): array
    {
        $headers = fgetcsv($handle) ?: [];

        return array_map(
            // Tolerate "Employee Number", "employee_number", and a UTF-8 BOM.
            fn ($header) => str_replace(' ', '_', strtolower(trim((string) $header, " \t\n\r\0\x0B\u{FEFF}"))),
            $headers,
        );
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

    private function validate(array $data, $employees): ?string
    {
        if (blank($data['employee_number'] ?? null)) {
            return 'employee_number is empty.';
        }

        if (! isset($employees[$data['employee_number']])) {
            return "no employee with number {$data['employee_number']}.";
        }

        try {
            $date = Carbon::parse($data['date']);
        } catch (\Throwable) {
            return "could not read the date '{$data['date']}'.";
        }

        if ($date->isFuture()) {
            return 'the date is in the future.';
        }

        foreach (['time_in', 'time_out', 'break_out', 'break_in'] as $field) {
            $value = $data[$field] ?? null;

            if (filled($value) && $this->time($value) === null) {
                return "could not read {$field} '{$value}' — use HH:MM.";
            }
        }

        if (filled($data['time_out'] ?? null) && blank($data['time_in'] ?? null)) {
            return 'a time_out was given without a time_in.';
        }

        return null;
    }

    /** Normalises "8:05", "08:05:00", and "08:05" to "08:05". */
    private function time(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
