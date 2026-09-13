<?php

namespace Database\Factories;

use App\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewCycle>
 */
class ReviewCycleFactory extends Factory
{
    protected $model = ReviewCycle::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        $year = 2024 + (++self::$sequence);

        return [
            'name' => "FY{$year} Annual Review",
            'type' => 'annual',
            'period_start' => "{$year}-01-01",
            'period_end' => "{$year}-12-31",
            'status' => ReviewCycle::STATUS_OPEN,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => ReviewCycle::STATUS_CLOSED]);
    }
}
