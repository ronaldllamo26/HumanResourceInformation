<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformanceReview>
 */
class PerformanceReviewFactory extends Factory
{
    protected $model = PerformanceReview::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'review_cycle_id' => ReviewCycle::factory(),
            'reviewer_id' => User::factory(),
            'reviewer_type' => 'supervisor',
            'status' => PerformanceReview::STATUS_DRAFT,
            'overall_rating' => null,
        ];
    }

    public function submitted(float $rating = 4.0): static
    {
        return $this->state(fn () => [
            'status' => PerformanceReview::STATUS_SUBMITTED,
            'overall_rating' => $rating,
            'submitted_at' => now(),
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['reviewer_type' => $type]);
    }
}
