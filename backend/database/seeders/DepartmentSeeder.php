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
            'name' => 'Human Resource Information Management',
            'description' => 'Oversees core workforce records, master data, employee profiles, and system access.',
            'positions' => [
                ['HRIS-MGR', 'HRIS Lead Manager', 'SG-18', 50000, 70000],
                ['HRIS-ANL', 'HR Systems Analyst', 'SG-14', 30000, 45000],
                ['HRIS-SPC', 'Workforce Records Specialist', 'SG-10', 20000, 30000],
            ],
        ],
        'CNB' => [
            'name' => 'Employee Development, Compliance and Benefits',
            'description' => 'Runs training and development, statutory compliance, mandatory benefits, and labor standards.',
            'positions' => [
                ['CNB-MGR', 'Employee Development & Benefits Manager', 'SG-18', 45000, 65000],
                /*
                 * Development is the half this department gained when it was
                 * renamed, so it needs somebody to do it: a compliance officer
                 * and a benefits specialist between them do not run a training
                 * calendar, and the Qualifications section on a 201 file is
                 * where completed trainings are recorded.
                 */
                ['CNB-TRN', 'Training & Development Officer', 'SG-14', 30000, 45000],
                ['CNB-LND', 'Learning & Development Specialist', 'SG-12', 25000, 38000],
                ['CNB-CMP', 'Labor Standards Compliance Officer', 'SG-14', 30000, 45000],
                ['CNB-BEN', 'Compensation & Benefits Specialist', 'SG-12', 25000, 38000],
            ],
        ],
        'GSA' => [
            // Was "Governance Safety & Safety Administration", which said
            // safety twice and read as a typo rather than a department.
            'name' => 'Governance, Safety and Administration',
            'description' => 'Directs organizational governance, occupational safety standards, risk mitigation, and protocols.',
            'positions' => [
                ['GSA-DIR', 'Governance & Safety Director', 'SG-20', 60000, 85000],
                /*
                 * "Safety Officer" is the title DOLE accredits under OSH
                 * Standards (D.O. 198-18), and a fleet operator is required to
                 * have one — so the title is the regulator's rather than an
                 * invented one.
                 */
                ['GSA-SOF', 'Safety Officer', 'SG-14', 30000, 45000],
                ['GSA-INS', 'Safety Inspector & Compliance Auditor', 'SG-12', 25000, 38000],
                ['GSA-ADM', 'Governance & Administration Officer', 'SG-12', 25000, 38000],
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
        /*
         * Fleet & Transportation Management — the department that was missing
         * from this list entirely, on a Fleet & Transportation HRIS.
         *
         * It matters more than its position count suggests: this is where the
         * **deployable** workforce sits. Every other department here is the
         * agency running itself (`employment_category` internal); these are
         * largely the people billed to a client, and they are the reason
         * `LicenseVerifier`, the DL codes, the conditions and Deployment
         * Readiness exist at all.
         *
         * **The driver titles carry the word "Driver" deliberately.**
         * `config('onboarding.by_position')` matches the fragment `driver` and
         * requires a driver's licence as a **blocking** document — so a title
         * worded "Motor Vehicle Operator" would quietly create a role the 201
         * File Status screen never asks a licence of, and the first anybody
         * would know is a dispatcher sending out somebody with nothing on
         * file. The title is doing work here, not just labelling.
         */
        'FTM' => [
            'name' => 'Fleet & Transportation Management',
            'description' => 'Runs vehicle operations, dispatch, driver deployment, and fleet maintenance for the agency and its clients.',
            'positions' => [
                ['FTM-MGR', 'Fleet & Transportation Manager', 'SG-18', 45000, 65000],
                ['FTM-SUP', 'Transport Operations Supervisor', 'SG-14', 30000, 45000],
                ['FTM-DSP', 'Dispatcher', 'SG-10', 20000, 30000],
                // The bulk deployable role, and the one the licence rules are
                // written for.
                ['FTM-DRV', 'Professional Driver', 'SG-08', 18000, 26000],
                ['FTM-DRH', 'Heavy Vehicle Driver', 'SG-10', 22000, 32000],
                /*
                 * Deliberately *not* worded with "driver": an operator works
                 * a machine rather than driving on a public road, so the
                 * blocking licence requirement does not apply to them — and
                 * the honest cost of that is stated rather than worked around
                 * by bending the title. If a licence should be required of
                 * operators too, that is a line in `config/onboarding.php`,
                 * not a word smuggled into a job title.
                 */
                ['FTM-HEO', 'Heavy Equipment Operator', 'SG-10', 20000, 32000],
                ['FTM-MEC', 'Vehicle Maintenance Technician', 'SG-10', 20000, 30000],
            ],
        ],
        'FAM' => [
            'name' => 'Facilities & Administration Management',
            'description' => 'Maintains building facilities, office administration, physical resources, and general services.',
            'positions' => [
                ['FAM-MGR', 'Facilities & Admin Manager', 'SG-18', 45000, 65000],
                ['FAM-ADM', 'Administrative Services Officer', 'SG-12', 25000, 38000],
                ['FAM-CLK', 'Facilities & Office Clerk', 'SG-07', 16000, 24000],
            ],
        ],
        'BIA' => [
            'name' => 'Business Intelligence & Analytics System',
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
