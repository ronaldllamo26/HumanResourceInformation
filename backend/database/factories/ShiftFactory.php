<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'name' => 'Day Shift',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'grace_period_minutes' => 15,
            'is_night_shift' => false,
            'is_active' => true,
        ];
    }

    /** 22:00 to 07:00 — crosses midnight and sits inside the night-diff window. */
    public function night(): static
    {
        return $this->state(fn () => [
            'name' => 'Night Shift',
            'start_time' => '22:00',
            'end_time' => '07:00',
            'is_night_shift' => true,
        ]);
    }

    public function noGrace(): static
    {
        return $this->state(fn () => ['grace_period_minutes' => 0]);
    }
}
