<?php

namespace Database\Factories;

use App\Models\Kpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kpi>
 */
class KpiFactory extends Factory
{
    protected $model = Kpi::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->randomElement([
                'On-time delivery rate',
                'Safety incident record',
                'Fuel efficiency',
                'Vehicle upkeep',
                'Customer feedback',
                'Attendance reliability',
                'Documentation accuracy',
                'Team collaboration',
            ]),
            'description' => 'Measured across the review period.',
            'category' => fake()->randomElement(['Safety', 'Efficiency', 'Service', 'Conduct']),
            'measurement_unit' => '%',
            'default_weight' => 25,
            'department_id' => null,
            'position_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
