<?php

namespace Database\Factories;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AttendanceLog>
 */
class AttendanceLogFactory extends Factory
{
    protected $model = AttendanceLog::class;

    public function definition(): array
    {
        $date = Carbon::parse(fake()->dateTimeBetween('-30 days', 'yesterday'))->startOfDay();

        return [
            'employee_id' => Employee::factory(),
            'log_date' => $date->toDateString(),
            'time_in' => $date->copy()->setTime(8, 0),
            'time_out' => $date->copy()->setTime(17, 0),
            'status' => AttendanceLog::STATUS_PRESENT,
            'source' => 'manual',
            'hours_worked' => 8,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'night_diff_minutes' => 0,
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['log_date' => $date]);
    }

    public function absent(): static
    {
        return $this->state(fn () => [
            'time_in' => null,
            'time_out' => null,
            'status' => AttendanceLog::STATUS_ABSENT,
            'hours_worked' => 0,
        ]);
    }

    public function late(int $minutes = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AttendanceLog::STATUS_LATE,
            'late_minutes' => $minutes,
        ]);
    }
}
