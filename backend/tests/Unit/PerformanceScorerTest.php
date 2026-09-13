<?php

namespace Tests\Unit;

use App\Services\PerformanceScorer;
use Tests\TestCase;

class PerformanceScorerTest extends TestCase
{
    private PerformanceScorer $scorer;

    // --- Review score -----------------------------------------------------

    public function test_a_review_score_is_the_weighted_mean_of_its_ratings(): void
    {
        // (5 x 50 + 3 x 30 + 1 x 20) / 100 = 3.60
        $score = $this->scorer->reviewScore([
            ['rating' => 5, 'weight' => 50],
            ['rating' => 3, 'weight' => 30],
            ['rating' => 1, 'weight' => 20],
        ]);

        $this->assertSame(3.6, $score);
    }

    public function test_weighting_actually_changes_the_outcome(): void
    {
        $heavyOnTheGoodKpi = $this->scorer->reviewScore([
            ['rating' => 5, 'weight' => 80],
            ['rating' => 2, 'weight' => 20],
        ]);

        $heavyOnTheWeakKpi = $this->scorer->reviewScore([
            ['rating' => 5, 'weight' => 20],
            ['rating' => 2, 'weight' => 80],
        ]);

        $this->assertSame(4.4, $heavyOnTheGoodKpi);
        $this->assertSame(2.6, $heavyOnTheWeakKpi);
    }

    public function test_an_unweighted_scorecard_falls_back_to_a_plain_average(): void
    {
        $score = $this->scorer->reviewScore([
            ['rating' => 4, 'weight' => 0],
            ['rating' => 2, 'weight' => 0],
        ]);

        $this->assertSame(3.0, $score);
    }

    public function test_a_review_with_no_ratings_has_no_score(): void
    {
        $this->assertNull($this->scorer->reviewScore([]));
    }

    // --- Composite 360 score ----------------------------------------------

    public function test_the_composite_blends_all_four_perspectives(): void
    {
        // 4 x 0.60 + 5 x 0.10 + 3 x 0.20 + 2 x 0.10 = 3.70
        $score = $this->scorer->compositeScore([
            'supervisor' => 4.0,
            'self' => 5.0,
            'peer' => 3.0,
            'subordinate' => 2.0,
        ]);

        $this->assertSame(3.7, $score);
    }

    public function test_missing_perspectives_are_renormalised_not_scored_as_zero(): void
    {
        // Only a supervisor reviewed: the score is theirs, not 60% of it.
        $this->assertSame(4.0, $this->scorer->compositeScore(['supervisor' => 4.0]));

        // Supervisor and self only: (4 x 0.60 + 5 x 0.10) / 0.70 = 4.14
        $this->assertSame(
            4.14,
            $this->scorer->compositeScore(['supervisor' => 4.0, 'self' => 5.0]),
        );
    }

    public function test_the_supervisor_carries_the_most_weight(): void
    {
        $supervisorHigh = $this->scorer->compositeScore(['supervisor' => 5.0, 'peer' => 1.0]);
        $peerHigh = $this->scorer->compositeScore(['supervisor' => 1.0, 'peer' => 5.0]);

        $this->assertGreaterThan($peerHigh, $supervisorHigh);
    }

    public function test_nulls_and_unknown_types_are_ignored(): void
    {
        $score = $this->scorer->compositeScore([
            'supervisor' => 4.0,
            'peer' => null,
            'customer' => 1.0, // not a configured perspective
        ]);

        $this->assertSame(4.0, $score);
    }

    public function test_an_employee_with_no_reviews_has_no_composite(): void
    {
        $this->assertNull($this->scorer->compositeScore([]));
        $this->assertNull($this->scorer->compositeScore(['supervisor' => null]));
    }

    public function test_several_reviews_of_one_perspective_are_averaged(): void
    {
        $this->assertSame(3.5, $this->scorer->averageOf([3.0, 4.0]));
        $this->assertNull($this->scorer->averageOf([]));
    }

    // --- Bands and weights -------------------------------------------------

    public function test_scores_map_to_performance_bands(): void
    {
        $this->assertSame('Outstanding', $this->scorer->band(4.8)['label']);
        $this->assertSame('Exceeds Expectations', $this->scorer->band(3.6)['label']);
        $this->assertSame('Meets Expectations', $this->scorer->band(3.0)['label']);
        $this->assertSame('Needs Improvement', $this->scorer->band(2.0)['label']);
        $this->assertSame('Unsatisfactory', $this->scorer->band(1.2)['label']);
        $this->assertNull($this->scorer->band(null));
    }

    public function test_scorecard_weights_must_add_up(): void
    {
        $this->assertTrue($this->scorer->weightsBalance(100));
        // Three-way splits round to 99.99 and should still pass.
        $this->assertTrue($this->scorer->weightsBalance(99.99));
        $this->assertFalse($this->scorer->weightsBalance(80));
        $this->assertFalse($this->scorer->weightsBalance(120));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new PerformanceScorer;
    }
}
