<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Batch creation runs every definition() before the first insert, so
     * Employee::nextEmployeeNumber() would hand out the same number to all of
     * them. Count in memory instead, starting past whatever is already stored.
     */
    private static ?int $sequence = null;

    public function definition(): array
    {
        if (self::$sequence === null) {
            self::$sequence = Employee::withTrashed()->count();
        }

        $employeeNumber = sprintf('PPM-%d-%04d', now()->year, ++self::$sequence);

        $hired = fake()->dateTimeBetween('-6 years', '-1 month');
        $status = fake()->randomElement([
            'regular', 'regular', 'regular', 'probationary', 'contractual', 'project-based',
        ]);

        return [
            'employee_number' => $employeeNumber,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-58 years', '-21 years'),
            'birth_place' => fake()->city(),
            'gender' => fake()->randomElement(['male', 'female']),
            'civil_status' => fake()->randomElement(['single', 'married', 'widowed', 'separated']),
            'nationality' => 'Filipino',
            'blood_type' => fake()->randomElement(['A+', 'B+', 'O+', 'AB+', 'O-']),

            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => '09'.fake()->numerify('#########'),
            'present_address' => fake()->address(),
            'permanent_address' => fake()->address(),

            'emergency_contact_name' => fake()->name(),
            'emergency_contact_relationship' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Child']),
            'emergency_contact_number' => '09'.fake()->numerify('#########'),

            'sss_number' => fake()->numerify('##-#######-#'),
            'philhealth_number' => fake()->numerify('##-#########-#'),
            'pagibig_number' => fake()->numerify('####-####-####'),
            'tin' => fake()->numerify('###-###-###-###'),

            'employment_status' => $status,
            'employment_type' => fake()->randomElement(['full_time', 'full_time', 'part_time']),
            'date_hired' => $hired,
            'date_regularized' => $status === 'regular'
                ? fake()->dateTimeBetween($hired, '-1 week')
                : null,

            'basic_salary' => fake()->randomElement([18000, 21000, 25000, 32000, 45000, 60000, 85000]),
            'pay_frequency' => 'semi_monthly',
            'bank_name' => fake()->randomElement(['BDO', 'BPI', 'Metrobank', 'Landbank', 'UnionBank']),
            'bank_account_number' => fake()->numerify('##########'),

            'status' => 'active',
        ];
    }

    /** Drivers carry a licence; used for Operations headcount. */
    public function driver(): static
    {
        return $this->state(function (array $attributes) {
            /*
             * A licence expires on the holder's birthday — confirmed on the
             * card this was modelled from, and checked by LicenseVerifier. A
             * factory that ignored it would flag every seeded driver, which is
             * exactly what the old three-letter licence number did.
             */
            $birth = isset($attributes['birth_date'])
                ? Carbon::parse($attributes['birth_date'])
                : null;

            $expiry = fake()->dateTimeBetween('-3 months', '+4 years');

            if ($birth) {
                $expiry = Carbon::parse($expiry)->setMonth($birth->month)->setDay($birth->day);
            }

            return [
                // One letter and ten digits, printed N01-23-456789. The old
                // pattern was three letters and eight digits, which is not a
                // shape the LTO issues — and RecordIntegrityChecker rightly
                // flagged every seeded driver because of it.
                'drivers_license_number' => fake()->bothify('?##-##-######'),

                /*
                 * Real DL codes, not the retired numeric restriction codes.
                 * The seeded set was '1,2' / '3,8', which is the scheme a
                 * current card no longer carries — and against the real list
                 * every one of them is an unknown code.
                 */
                'license_dl_codes' => fake()->randomElement(['A', 'B', 'B,C', 'C', 'C,CE', 'B,D']),

                // Most licences carry none; the one in five that does is what
                // makes the Deployment Readiness warning worth having.
                'license_conditions' => fake()->optional(0.2)->randomElement(['1', '4', '1,4']),

                'license_expiry' => $expiry,
            ];
        });
    }

    public function onLeave(): static
    {
        return $this->state(fn () => ['status' => 'on_leave']);
    }
}
