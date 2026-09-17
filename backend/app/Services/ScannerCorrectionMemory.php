<?php

namespace App\Services;

use App\Models\DocumentScan;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What the scanner has learned from HR's own corrections.
 *
 * This is the honest version of "train your AI" for a system with this much
 * data. Nothing is retrained and no model weights move: the scanner already
 * records every proposal and what the document was **actually filed as**
 * (`document_scans.proposed` / `saved`, the rows Scanner Accuracy is measured
 * from), and those rows already hold the one signal with a person behind it —
 * *this printed heading turned out to be that type*. This service turns them
 * into rules the classifier reads on the next scan, so a type HR has fixed
 * twice stops coming back wrong a third time.
 *
 * Three properties worth keeping:
 *
 * - **Nothing new is sent anywhere.** The rules are consulted in PHP inside
 *   `DocumentScanner::resolveType()`. Feeding past readings back to the
 *   provider as examples would be a fresh cross-border transfer of other
 *   employees' documents under RA 10173, for a gain this achieves without it.
 * - **A heading HR disagreed about teaches nothing.** Two different filed
 *   types behind one heading means one of the filings is wrong and the values
 *   cannot say which, so no rule is made — the same refusal the two-parents
 *   misread and the two-people-one-name match already make.
 * - **A learned type is never `type_certain`.** Batch filing only files a
 *   certain type unattended, and certainty is reserved for the two sources
 *   that have been measured: a number already on the 201 file, and the
 *   config's curated heading keywords. A rule the system wrote for itself has
 *   not been measured yet, so it proposes and a person still confirms.
 */
class ScannerCorrectionMemory
{
    private const CACHE_KEY = 'scanner.learned_types';

    private const CACHE_TTL = 600;

    /** A heading this short says nothing; one carrying a long digit run may be an ID number, not a title. */
    private const MIN_HEADING = 4;

    public function isEnabled(): bool
    {
        return (bool) config('scanner.learning.enabled', true);
    }

    /**
     * The type learned for a printed heading, or null.
     *
     * Exact match on the normalised heading first, then containment either
     * way — the model condenses a three-line letterhead differently between
     * scans ("TIN ID" one time, "BIR TIN ID" the next), so requiring the same
     * string twice would learn nothing that is ever used again.
     */
    public function typeFor(?string $heading): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $key = $this->normalise($heading);

        if ($key === null) {
            return null;
        }

        $rules = $this->rules();

        if ($rules->has($key)) {
            return $rules[$key]['type'];
        }

        $match = $rules->first(
            fn (array $rule, string $learned) => strlen($learned) >= 6
                && (str_contains($key, $learned) || str_contains($learned, $key)),
        );

        return $match['type'] ?? null;
    }

    /**
     * Every rule the corrections support, strongest first.
     *
     * Cached for ten minutes rather than stored in a table: the rows are the
     * record and this is a read of them, so there is no second copy to fall
     * out of step — and a rule that disappears when HR deletes the scan it
     * came from is the correct behaviour rather than a bug.
     *
     * @return Collection<string, array{heading: string, type: string, confirmations: int, last_seen: string|null}>
     */
    public function rules(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => $this->derive(),
        );
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return Collection<string, array<string, mixed>> */
    private function derive(): Collection
    {
        $lookback = now()->subDays((int) config('scanner.learning.lookback_days', 365));

        $observations = DocumentScan::query()
            ->whereNotNull('saved')
            ->where('created_at', '>=', $lookback)
            ->latest('id')
            ->limit(2000)
            ->get(['proposed', 'saved', 'created_at'])
            ->map(function (DocumentScan $scan) {
                $heading = $this->normalise($scan->proposed['heading'] ?? null);
                $type = $scan->saved['type'] ?? null;

                return $heading && in_array($type, EmployeeDocument::TYPES, true)
                    ? ['heading' => $heading, 'type' => $type, 'at' => $scan->created_at]
                    : null;
            })
            ->filter();

        $minimum = max(1, (int) config('scanner.learning.min_confirmations', 2));

        return $observations
            ->groupBy('heading')
            ->map(function (Collection $group, string $heading) {
                $types = $group->pluck('type')->unique();

                // Filed as two different types under one heading: one of the
                // two is wrong and nothing here can say which.
                if ($types->count() !== 1) {
                    return null;
                }

                return [
                    'heading' => $heading,
                    'type' => $types->first(),
                    'confirmations' => $group->count(),
                    'last_seen' => $group->max('at')?->toDateString(),
                ];
            })
            ->filter(fn (?array $rule) => $rule !== null && $rule['confirmations'] >= $minimum)
            ->sortByDesc('confirmations')
            ->take((int) config('scanner.learning.max_rules', 50));
    }

    /**
     * One spelling for a heading: lowercase, punctuation out, spaces
     * collapsed. A heading carrying six or more digits in a row is refused —
     * a transcription that long is an ID number wearing a title's label, and
     * learning it would put somebody's number in a rule list.
     */
    private function normalise(?string $heading): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strtolower((string) $heading)) ?? '');
        $text = trim(preg_replace('/[^a-z0-9 ]/', ' ', $text) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if (strlen($text) < self::MIN_HEADING || preg_match('/\d{6,}/', $text)) {
            return null;
        }

        return $text;
    }
}
