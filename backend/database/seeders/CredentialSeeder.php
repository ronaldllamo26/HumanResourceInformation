<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\EmployeeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Gives employees the 201-file documents a transport company actually keeps,
 * with a realistic spread of expiry dates — mostly in date, a few inside their
 * renewal window, one or two already lapsed — so the Credentials screen has
 * something to show.
 */
class CredentialSeeder extends Seeder
{
    /**
     * Days from today. Negative is already expired.
     *
     * The spread has to leave *most* people clear, because a screen where
     * everybody is flagged says nothing. An earlier version put four of ten
     * licences and three of nine medicals inside their warning windows, and
     * the two compounded: 38 of 39 employees carried at least one finding, so
     * the one person who was actually fine was invisible among them.
     *
     * A licence runs five years and a medical one year, so realistically only
     * a small share of either sits within its renewal window on any given day.
     * Roughly two in thirteen and two in twelve here, plus one already lapsed
     * of each — enough to have something to show, few enough to mean something.
     */
    private const LICENCE_OFFSETS = [
        1_460, 1_180, 900, 720, 610, 500, 400, 310, 240, 170, 110, 44, -6,
    ];

    private const MEDICAL_OFFSETS = [
        330, 300, 275, 250, 220, 195, 170, 140, 115, 90, 32, -3,
    ];

    private const CLEARANCE_OFFSETS = [
        340, 310, 285, 260, 230, 205, 180, 150, 120, 95, 38,
    ];

    /** @var Collection<string, int> employee|type pairs already on file */
    private $existing;

    public function run(): void
    {
        /*
         * Fills gaps rather than refusing to run, the same property the
         * attendance seeder has. Bailing out on the first existing document
         * meant a database seeded before the blocking types were added could
         * never be topped up without deleting 201 files that were already
         * filed against employees.
         */
        $this->existing = EmployeeDocument::query()
            ->get(['employee_id', 'type'])
            ->map(fn ($document) => $document->employee_id.'|'.$document->type)
            ->flip();

        $employees = Employee::orderBy('id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $index => $employee) {
            $this->document(
                $employee,
                'drivers_license',
                "Professional Driver's Licence",
                self::LICENCE_OFFSETS[$index % count(self::LICENCE_OFFSETS)],
            );

            $this->document(
                $employee,
                'medical',
                'Annual Medical Certificate',
                self::MEDICAL_OFFSETS[$index % count(self::MEDICAL_OFFSETS)],
            );

            /*
             * The four types `config('onboarding.documents')` marks as
             * blocking have to be mostly present, or every screen that reads
             * them is uniformly red and therefore says nothing. Before this,
             * no employee had a contract or a government ID at all, so 201
             * File Status flagged all 39 as incomplete and Deployment
             * Readiness blocked all 39 — a finding that points at everyone
             * points at no one.
             *
             * So: most people are complete, and a deliberate minority is not.
             * Every seventh has no contract, every eleventh no government ID —
             * co-prime strides, so the gaps land on different people instead
             * of stacking on the same unlucky few.
             */
            if ($index % 7 !== 0) {
                $this->document($employee, 'contract', 'Employment Contract', null);
            }

            if ($index % 11 !== 0) {
                $this->document($employee, 'government_id', 'PhilSys National ID', null);
            }

            /*
             * A clearance runs a year. The old spread was `90 - (i % 5) * 30`
             * — 90, 60, 30, 0, -30 — and against a 45-day warning window that
             * put three of every five inside it, which is most of the noise a
             * reader had to look past to find a real finding.
             */
            if ($index % 9 !== 0) {
                $this->document(
                    $employee,
                    'clearance',
                    'NBI Clearance',
                    self::CLEARANCE_OFFSETS[$index % count(self::CLEARANCE_OFFSETS)],
                );
            }

            // Nearly everyone hands one in; the point of tracking it is the
            // few who did not. Giving it to one employee in four made "missing
            // résumé" the loudest finding on the screen and the least useful.
            if ($index % 6 !== 0) {
                $this->document($employee, 'resume', 'Curriculum Vitae', null);
            }
        }
    }

    private function document(
        Employee $employee,
        string $type,
        string $title,
        ?int $expiresInDays,
    ): void {
        // One of each type per employee: re-running must not stack five
        // contracts on the same 201 file.
        if ($this->existing->has($employee->id.'|'.$type)) {
            return;
        }

        $path = "employees/{$employee->id}/{$type}.txt";

        // A real file behind the row, so the download link works in a demo
        // instead of 404-ing on a path that was never written.
        Storage::disk(EmployeeService::DOCUMENT_DISK)->put(
            $path,
            "Placeholder for {$title} — {$employee->full_name}.",
        );

        EmployeeDocument::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'title' => $title,
            'file_path' => $path,
            'file_name' => "{$type}.txt",
            'mime_type' => 'text/plain',
            'file_size' => 64,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => $expiresInDays === null
                ? null
                : now()->addDays($expiresInDays)->toDateString(),
        ]);
    }
}
