<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        $start = Carbon::today()->addDays(fake()->numberBetween(1, 20));

        return [
            'reference_number' => sprintf('LV-%d-%04d', now()->year, ++self::$sequence),
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'start_date' => $start,
            'end_date' => $start->copy()->addDay(),
            'days_requested' => 2,
            'is_half_day' => false,
            'reason' => 'Family matters that need attention.',
            'status' => LeaveRequest::STATUS_PENDING,
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function approved(): static
    {
        return $this->status(LeaveRequest::STATUS_APPROVED);
    }

    public function endorsed(): static
    {
        return $this->status(LeaveRequest::STATUS_SUPERVISOR_APPROVED);
    }

    public function on(string $start, ?string $end = null): static
    {
        return $this->state(fn () => [
            'start_date' => $start,
            'end_date' => $end ?? $start,
        ]);
    }
}
