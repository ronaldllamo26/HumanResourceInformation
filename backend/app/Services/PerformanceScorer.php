<?php

namespace App\Services;

/**
 * Performance scoring arithmetic.
 *
 * Database-free and driven by config/performance.php. A review score is the
 * weighted mean of its KPI ratings; an employee's score for a cycle is the
 * weighted blend of the perspectives that actually reviewed them.
 */
class PerformanceScorer
{
    /**
     * Weighted mean of a review's KPI ratings.
     *
     * @param  array<int, array{rating: float, weight: float}>  $ratings
     */
    public function reviewScore(array $ratings): ?float
    {
        if ($ratings === []) {
            return null;
        }

        $weightTotal = array_sum(array_column($ratings, 'weight'));

        // An unweighted scorecard still deserves a score: fall back to a plain
        // average rather than dividing by zero.
        if ($weightTotal <= 0) {
            return round(array_sum(array_column($ratings, 'rating')) / count($ratings), 2);
        }

        $weighted = 0.0;

        foreach ($ratings as $rating) {
            $weighted += $rating['rating'] * $rating['weight'];
        }

        return round($weighted / $weightTotal, 2);
    }

    /**
     * Blends the four 360 perspectives into one score.
     *
     * Missing perspectives are not treated as zero — the remaining weights are
     * re-normalised, so an employee with only a supervisor review still scores
     * on the same 1–5 scale.
     *
     * @param  array<string, float|null>  $scoresByType  keyed by reviewer type
     */
    public function compositeScore(array $scoresByType): ?float
    {
        $weights = config('performance.reviewer_weights');

        $weighted = 0.0;
        $applied = 0.0;

        foreach ($scoresByType as $type => $score) {
            if ($score === null || ! isset($weights[$type])) {
                continue;
            }

            $weighted += $score * $weights[$type];
            $applied += $weights[$type];
        }

        if ($applied <= 0) {
            return null;
        }

        return round($weighted / $applied, 2);
    }

    /**
     * Averages several reviews of the same perspective — a 360 can gather more
     * than one peer or subordinate.
     *
     * @param  array<int, float>  $scores
     */
    public function averageOf(array $scores): ?float
    {
        $scores = array_values(array_filter($scores, fn ($score) => $score !== null));

        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 2);
    }

    /**
     * The band a score falls into.
     *
     * @return array{label: string, variant: string}|null
     */
    public function band(?float $score): ?array
    {
        if ($score === null) {
            return null;
        }

        foreach (config('performance.performance_bands') as $band) {
            if ($score >= $band['floor']) {
                return ['label' => $band['label'], 'variant' => $band['variant']];
            }
        }

        return null;
    }

    /** Whether a scorecard's weights add up to the required total. */
    public function weightsBalance(float $weightTotal): bool
    {
        // Tolerant of the rounding a 3-way split produces (33.33 x 3).
        return abs($weightTotal - config('performance.required_weight_total')) < 0.5;
    }
}
