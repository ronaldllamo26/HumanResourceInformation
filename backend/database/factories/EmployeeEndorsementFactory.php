<?php

namespace Database\Factories;

use App\Models\EmployeeEndorsement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeEndorsement>
 */
class EmployeeEndorsementFactory extends Factory
{
    protected $model = EmployeeEndorsement::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        $payload = [
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => '09'.fake()->numerify('#########'),
            'birth_date' => fake()->dateTimeBetween('-55 years', '-21 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'civil_status' => fake()->randomElement(['single', 'married']),
            'nationality' => 'Filipino',
            'present_address' => fake()->address(),
        ];

        return [
            /*
             * Shaped like something a recruitment system would issue rather
             * than a bare number, because the whole point of the column is
             * that it is *their* identifier — a sequence that happens to
             * match ours would hide a mistake where the two were confused.
             */
            'reference' => 'C1-'.now()->year.'-'.Str::upper(Str::random(6)),
            'source' => 'core1',
            'first_name' => $first,
            'last_name' => $last,
            'email' => $payload['email'],
            'mobile_number' => $payload['mobile_number'],
            'position_title' => fake()->randomElement(['Driver', 'Dispatcher', 'Mechanic']),
            'client_name' => null,
            'date_hired' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'payload' => $payload,
            'status' => EmployeeEndorsement::STATUS_PENDING,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn () => [
            'status' => EmployeeEndorsement::STATUS_APPROVED,
            'decided_at' => now()->subDays(2),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn () => [
            'status' => EmployeeEndorsement::STATUS_REJECTED,
            'decided_at' => now()->subDay(),
            'decision_note' => 'No LTO licence on file — cannot be deployed as a driver.',
        ]);
    }
}
