<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /** Org structure for a fleet & transportation operation. */
    private const STRUCTURE = [
        'OPS' => [
            'name' => 'Fleet Operations',
            'positions' => [
                ['OPS-MGR', 'Operations Manager', 'SG-18'],
                ['OPS-DRV', 'Professional Driver', 'SG-08'],
                ['OPS-HLP', 'Delivery Helper', 'SG-05'],
                ['OPS-DSP', 'Dispatcher', 'SG-10'],
            ],
        ],
        'MNT' => [
            'name' => 'Fleet Maintenance',
            'positions' => [
                ['MNT-SUP', 'Maintenance Supervisor', 'SG-15'],
                ['MNT-MEC', 'Vehicle Mechanic', 'SG-10'],
                ['MNT-ELE', 'Auto Electrician', 'SG-10'],
            ],
        ],
        'HRD' => [
            'name' => 'Human Resources',
            'positions' => [
                ['HRD-MGR', 'HR Manager', 'SG-18'],
                ['HRD-OFF', 'HR Officer', 'SG-12'],
                ['HRD-ASC', 'HR Associate', 'SG-08'],
            ],
        ],
        'FIN' => [
            'name' => 'Finance & Accounting',
            'positions' => [
                ['FIN-MGR', 'Finance Manager', 'SG-18'],
                ['FIN-ACC', 'Accountant', 'SG-14'],
                ['FIN-PAY', 'Payroll Officer', 'SG-12'],
            ],
        ],
        'SAF' => [
            'name' => 'Safety & Compliance',
            'positions' => [
                ['SAF-OFF', 'Safety Officer', 'SG-14'],
                ['SAF-INS', 'Compliance Inspector', 'SG-12'],
            ],
        ],
        'ADM' => [
            'name' => 'Administration',
            'positions' => [
                ['ADM-OFF', 'Admin Officer', 'SG-12'],
                ['ADM-CLK', 'Admin Clerk', 'SG-07'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::STRUCTURE as $code => $definition) {
            $department = Department::updateOrCreate(
                ['code' => $code],
                ['name' => $definition['name'], 'is_active' => true],
            );

            foreach ($definition['positions'] as [$positionCode, $title, $grade]) {
                Position::updateOrCreate(
                    ['code' => $positionCode],
                    [
                        'department_id' => $department->id,
                        'title' => $title,
                        'salary_grade' => $grade,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
