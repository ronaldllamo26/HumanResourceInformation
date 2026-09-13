<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => 'Vacation Leave',
            'description' => null,
            'default_credits' => 15,
            'is_paid' => true,
            'requires_attachment' => false,
            'is_convertible_to_cash' => false,
            'max_consecutive_days' => null,
            'min_days_notice' => 0,
            'is_active' => true,
        ];
    }

    /** Leave without pay — never touches the credit ledger. */
    public function unpaid(): static
    {
        return $this->state(fn () => [
            'name' => 'Leave Without Pay',
            'default_credits' => 0,
            'is_paid' => false,
        ]);
    }

    public function requiringAttachment(): static
    {
        return $this->state(fn () => ['requires_attachment' => true]);
    }

    public function withNotice(int $days): static
    {
        return $this->state(fn () => ['min_days_notice' => $days]);
    }
}
