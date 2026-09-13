<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeEndorsement;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A Core 1 inbox with something in it.
 *
 * Three pending, one approved, one declined — enough that the queue, the
 * summary tiles, and both decided states all have something to show on a fresh
 * install. An empty inbox on first run reads as broken rather than as quiet.
 *
 * The approved one is pointed at an employee that already exists rather than
 * creating a new one: the seeded workforce is generated straight from the
 * factory, and inventing a parallel "hired through Core 1" person would put
 * somebody on the payroll twice. What is being demonstrated is the link, and
 * an existing employee demonstrates it exactly as well.
 */
class EndorsementSeeder extends Seeder
{
    public function run(): void
    {
        // Re-running must not double the queue. Same shape as the leave
        // accrual and salary cache commands: safe to run again.
        if (EmployeeEndorsement::exists()) {
            return;
        }

        $positions = Position::where('is_active', true)->pluck('title');
        $client = Client::where('is_active', true)->first();
        $admin = User::where('role', User::ROLE_ADMIN)->first();

        // --- Waiting on a decision ---
        EmployeeEndorsement::factory()->create([
            'position_title' => $positions->first() ?? 'Driver',
            'client_name' => $client?->name,
        ]);

        EmployeeEndorsement::factory()->count(2)->create([
            'position_title' => $positions->random() ?? 'Dispatcher',
        ]);

        // --- Already answered, so both outcomes are visible ---
        EmployeeEndorsement::factory()->approved()->create([
            'employee_id' => Employee::inRandomOrder()->value('id'),
            'decided_by' => $admin?->id,
        ]);

        EmployeeEndorsement::factory()->rejected()->create([
            'decided_by' => $admin?->id,
        ]);
    }
}
