<?php

namespace App\Services;

use App\Models\DocumentScan;
use Illuminate\Support\Collection;

/**
 * How well the document scanner actually performs, measured rather than
 * claimed.
 *
 * The model reports its own `confidence` on every scan, and that field was
 * measured useless: across six documents it answered "high" six times,
 * including on the readings that were wrong. Nothing can be built on it. So
 * this counts the only thing that carries information — **what HR did with
 * the proposal**. A field kept as offered was right; a field typed over was
 * not.
 *
 * That makes this two things at once. It is a screen HR can use to see where
 * the scanner needs watching, and it is the instrument that produces the
 * accuracy figures a capstone has to report: per field, per document type,
 * over a real number of real documents.
 *
 * Database-free arithmetic, like the other calculators — it is handed rows and
 * returns figures, so every rule in it is unit-testable without a fixture.
 */
class ScanAccuracyReport
{
    /**
     * @param  Collection<int, DocumentScan>  $scans
     * @return array<string, mixed>
     */
    public function build(Collection $scans): array
    {
        $completed = $scans->filter(fn (DocumentScan $scan) => $scan->saved !== null);

        return [
            'totals' => $this->totals($scans, $completed),
            'fields' => $this->byField($completed),
            'types' => $this->byType($completed),
            'sources' => $this->byTypeSource($completed),
            'recent' => $this->recent($scans),
        ];
    }

    /**
     * @param  Collection<int, DocumentScan>  $scans
     * @param  Collection<int, DocumentScan>  $completed
     */
    private function totals(Collection $scans, Collection $completed): array
    {
        $fieldsOffered = 0;
        $fieldsKept = 0;

        foreach ($completed as $scan) {
            $fieldsOffered += count($scan->accepted ?? []) + count($scan->corrected ?? []);
            $fieldsKept += count($scan->accepted ?? []);
        }

        /*
         * A scan with nothing corrected is one where every value the scanner
         * offered was filed as offered. It is the figure worth leading with,
         * because it answers "how often does this just work" — which is the
         * question, not "what fraction of individual fields were right".
         */
        $clean = $completed->filter(fn (DocumentScan $scan) => ($scan->corrected ?? []) === [])->count();

        return [
            'scans' => $scans->count(),
            // Scans that never became a document. Counted, because a reading
            // bad enough to abandon is a failure and excluding it would
            // flatter every other number here.
            'abandoned' => $scans->count() - $completed->count(),
            'filed' => $completed->count(),
            'clean' => $clean,
            'clean_rate' => $this->rate($clean, $completed->count()),
            'fields_offered' => $fieldsOffered,
            'fields_kept' => $fieldsKept,
            'field_rate' => $this->rate($fieldsKept, $fieldsOffered),
            'median_ms' => $this->median($scans->pluck('duration_ms')->filter()->values()->all()),
        ];
    }

    /**
     * Per field, because they do not fail together.
     *
     * The split matters more than the average: this scanner transcribes
     * numbers and dates well and judges categories poorly, so a single
     * headline accuracy figure would hide the one weakness worth acting on.
     *
     * @param  Collection<int, DocumentScan>  $completed
     */
    private function byField(Collection $completed): array
    {
        $rows = [];

        foreach (DocumentScan::COMPARED as $field) {
            $kept = $completed->filter(fn (DocumentScan $s) => in_array($field, $s->accepted ?? [], true))->count();
            $fixed = $completed->filter(fn (DocumentScan $s) => in_array($field, $s->corrected ?? [], true))->count();

            $rows[] = [
                'field' => $field,
                'offered' => $kept + $fixed,
                'kept' => $kept,
                'corrected' => $fixed,
                'rate' => $this->rate($kept, $kept + $fixed),
            ];
        }

        // Worst first: the screen exists to show where to look.
        return collect($rows)
            ->sortBy(fn (array $row) => $row['offered'] === 0 ? 2 : $row['rate'])
            ->values()
            ->all();
    }

    /**
     * Per document type, on the type the document was *filed* as — the answer
     * a person settled on, not the one the scanner proposed.
     *
     * @param  Collection<int, DocumentScan>  $completed
     */
    private function byType(Collection $completed): array
    {
        return $completed
            ->groupBy(fn (DocumentScan $scan) => $scan->saved['type'] ?? 'other')
            ->map(function (Collection $group, string $type) {
                $clean = $group->filter(fn (DocumentScan $s) => ($s->corrected ?? []) === [])->count();

                return [
                    'type' => $type,
                    'scans' => $group->count(),
                    'clean' => $clean,
                    'rate' => $this->rate($clean, $group->count()),
                    // The single most useful column for the person reading
                    // this: which field this kind of document gets wrong.
                    'worst_field' => $this->worstField($group),
                ];
            })
            ->sortBy('rate')
            ->values()
            ->all();
    }

    /**
     * How each way of deciding the type actually performed.
     *
     * The five sources are ordered by an argument — a number a human filed
     * beats a printed heading beats an inference beats the model's guess — and
     * this is where that argument is checked against outcomes rather than
     * asserted. If the ordering is wrong, it shows here.
     *
     * @param  Collection<int, DocumentScan>  $completed
     */
    private function byTypeSource(Collection $completed): array
    {
        return $completed
            ->filter(fn (DocumentScan $scan) => ($scan->proposed['type_source'] ?? null) !== null)
            ->groupBy(fn (DocumentScan $scan) => $scan->proposed['type_source'])
            ->map(function (Collection $group, string $source) {
                $kept = $group->filter(
                    fn (DocumentScan $s) => in_array('type', $s->accepted ?? [], true),
                )->count();

                return [
                    'source' => $source,
                    'scans' => $group->count(),
                    'kept' => $kept,
                    'rate' => $this->rate($kept, $group->count()),
                ];
            })
            ->sortByDesc('rate')
            ->values()
            ->all();
    }

    /** @param  Collection<int, DocumentScan>  $scans */
    private function recent(Collection $scans): array
    {
        return $scans
            ->sortByDesc('created_at')
            ->take(25)
            ->map(fn (DocumentScan $scan) => [
                'id' => $scan->id,
                'employee' => $scan->employee?->full_name,
                'proposed_type' => $scan->proposed['type'] ?? null,
                'saved_type' => $scan->saved['type'] ?? null,
                'type_source' => $scan->proposed['type_source'] ?? null,
                'corrected' => $scan->corrected ?? [],
                'outcome' => $scan->saved === null
                    ? 'abandoned'
                    : (($scan->corrected ?? []) === [] ? 'clean' : 'corrected'),
                'duration_ms' => $scan->duration_ms,
                'scanned_at' => $scan->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @param  Collection<int, DocumentScan>  $group */
    private function worstField(Collection $group): ?string
    {
        $counts = [];

        foreach ($group as $scan) {
            foreach ($scan->corrected ?? [] as $field) {
                $counts[$field] = ($counts[$field] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return array_key_first($counts);
    }

    /** Null rather than zero when nothing was measured — they mean different things. */
    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }

    /**
     * The median, not the mean: the first scan after a reboot loads the model
     * into VRAM and takes forty seconds, and one of those drags an average
     * far enough to misdescribe every other scan in the set.
     *
     * @param  array<int, int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
