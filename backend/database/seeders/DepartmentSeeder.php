<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /** Org structure for enterprise human resource operations. */
    private const STRUCTURE = [
        'RND' => [
            'name' => 'Recruitment and Deployment',
            'description' => 'Handles talent acquisition, screening, hiring, and employee placement and deployment.',
            'positions' => [
                ['RND-MGR', 'Recruitment & Deployment Manager', 'SG-18', 45000, 65000],
                ['RND-OFF', 'Recruitment Officer', 'SG-12', 25000, 38000],
                ['RND-DEP', 'Deployment Coordinator', 'SG-10', 20000, 30000],
                ['RND-REC', 'Talent Sourcing Specialist', 'SG-08', 18000, 26000],
            ],
        ],
        'HRIS' => [
            'name' => 'Human Resource Information System',
            'description' => 'Oversees core workforce records, master data, employee profiles, and system access.',
            'positions' => [
                ['HRIS-MGR', 'HRIS Lead Manager', 'SG-18', 50000, 70000],
                ['HRIS-ANL', 'HR Systems Analyst', 'SG-14', 30000, 45000],
                ['HRIS-SPC', 'Workforce Records Specialist', 'SG-10', 20000, 30000],
            ],
        ],
        'CNB' => [
            'name' => 'Compliance and Benefits',
            'description' => 'Manages statutory compliance, compensation policies, mandatory benefits, and labor standards.',
            'positions' => [
                ['CNB-MGR', 'Compliance & Benefits Manager', 'SG-18', 45000, 65000],
                ['CNB-CMP', 'Labor Standards Compliance Officer', 'SG-14', 30000, 45000],
                ['CNB-BEN', 'Compensation & Benefits Specialist', 'SG-12', 25000, 38000],
            ],
        ],
        'GSA' => [
            'name' => 'Governance Safety & Safety Administration',
            'description' => 'Directs organizational governance, occupational safety standards, risk mitigation, and protocols.',
            'positions' => [
                ['GSA-DIR', 'Governance & Safety Director', 'SG-20', 60000, 85000],
                ['GSA-SOF', 'Safety Administration Officer', 'SG-14', 30000, 45000],
                ['GSA-INS', 'Safety Inspector & Compliance Auditor', 'SG-12', 25000, 38000],
            ],
        ],
        'FIN' => [
            'name' => 'Financial Management',
            'description' => 'Administers corporate finance, accounting operations, payroll disbursement, and fiscal controls.',
            'positions' => [
                ['FIN-MGR', 'Finance & Accounting Manager', 'SG-18', 50000, 75000],
                ['FIN-ACC', 'Senior Accountant', 'SG-14', 32000, 48000],
                ['FIN-PAY', 'Payroll Specialist', 'SG-12', 25000, 38000],
            ],
        ],
        'SCI' => [
            'name' => 'Supply Chain & Inventory',
            'description' => 'Manages procurement, logistics, fleet equipment inventory, and supply pipelines.',
            'positions' => [
                ['SCI-MGR', 'Supply Chain & Logistics Manager', 'SG-18', 45000, 65000],
                ['SCI-PRC', 'Procurement Officer', 'SG-12', 25000, 38000],
                ['SCI-INV', 'Warehouse & Inventory Specialist', 'SG-08', 18000, 26000],
                ['SCI-LOG', 'Fleet Logistics Coordinator', 'SG-10', 20000, 30000],
            ],
        ],
        'FAM' => [
            'name' => 'Facilities & Administrative Management',
            'description' => 'Maintains building facilities, office administration, physical resources, and general services.',
            'positions' => [
                ['FAM-MGR', 'Facilities & Admin Manager', 'SG-18', 45000, 65000],
                ['FAM-ADM', 'Administrative Services Officer', 'SG-12', 25000, 38000],
                ['FAM-CLK', 'Facilities & Office Clerk', 'SG-07', 16000, 24000],
            ],
        ],
        'BIA' => [
            'name' => 'Business Intelligence & Analytics',
            'description' => 'Drives business reporting, KPI monitoring, data analytics, and executive intelligence.',
            'positions' => [
                ['BIA-MGR', 'BI & Analytics Manager', 'SG-18', 55000, 80000],
                ['BIA-ANL', 'Business Intelligence Analyst', 'SG-14', 35000, 50000],
                ['BIA-RPT', 'Data Reporting & Metrics Specialist', 'SG-12', 28000, 42000],
            ],
        ],
        'CRM' => [
            'name' => 'Customer Relationship Management',
            'description' => 'Manages client partnerships, account servicing, client contracts, and stakeholder communications.',
            'positions' => [
                ['CRM-MGR', 'Client Relations Manager', 'SG-18', 45000, 65000],
                ['CRM-ACC', 'Key Account Executive', 'SG-14', 30000, 45000],
                ['CRM-SPC', 'Client Services Coordinator', 'SG-10', 20000, 30000],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::STRUCTURE as $code => $definition) {
            $department = Department::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_active' => true,
                ],
            );

            foreach ($definition['positions'] as [$positionCode, $title, $grade, $min, $max]) {
                Position::updateOrCreate(
                    ['code' => $positionCode],
                    [
                        'department_id' => $department->id,
                        'title' => $title,
                        'salary_grade' => $grade,
                        'min_salary' => $min,
                        'max_salary' => $max,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
