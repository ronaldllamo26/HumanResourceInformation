<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Gives a few employees allowances and loans, then computes a payroll run over
 * the seeded attendance so the screens have real figures.
 */
class PayrollSeeder extends Seeder
{
    public function run(PayrollService $payroll): void
    {
        if (PayrollPeriod::exists()) {
            return;
        }

        $processor = User::where('role', User::ROLE_HR_STAFF)->first()
            ?? User::where('role', User::ROLE_ADMIN)->first();

        if (! $processor) {
            return;
        }

        $this->seedAllowancesAndLoans();

        // Cover the fortnight the attendance seeder filled in.
        $end = Carbon::today()->subDay();
        $start = $end->copy()->subDays(14);

        $period = PayrollPeriod::create([
            'name' => $start->format('M j').' – '.$end->format('j, Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pay_date' => $end->copy()->addDays(5)->toDateString(),
            'frequency' => 'semi_monthly',
            'status' => PayrollPeriod::STATUS_DRAFT,
        ]);

        $run = $payroll->generate($period, $processor);
        $payroll->submitForApproval($run);

        /*
         * Carried through to paid, not left waiting for approval.
         *
         * Everything downstream of payroll reads *finalised* runs only — a
         * draft is still being corrected — so a run parked at for_approval
         * leaves 13th-month pay, compliance remittances, final pay, and every
         * employee's own payslip screen empty on a fresh install. Those look
         * broken rather than pending.
         *
         * The approver is deliberately an admin, not the HR processor: the
         * seeded data should model the separation of duties the policy
         * enforces, not work around it.
         */
        $approver = User::where('role', User::ROLE_ADMIN)->first();

        if ($approver && $approver->isNot($processor)) {
            $payroll->approve($run, $approver, 'Seeded run — approved for demonstration data.');
            $payroll->markPaid($run);
        }

        $this->command?->info(
            "Seeded payroll run {$run->run_number}: {$run->employee_count} payslip(s), "
            .'net '.number_format((float) $run->total_net, 2).", status {$run->refresh()->status}.",
        );
    }

    private function seedAllowancesAndLoans(): void
    {
        $employees = Employee::where('status', 'active')->get();

        if ($employees->isEmpty()) {
            return;
        }

        // Transportation for a third of the workforce, taxable meal for a few.
        foreach ($employees->random(min(12, $employees->count())) as $employee) {
            EmployeeAllowance::firstOrCreate(
                ['employee_id' => $employee->id, 'name' => 'Transportation'],
                [
                    'amount' => 2000,
                    'frequency' => 'monthly',
                    'is_taxable' => false,
                    'effective_from' => Carbon::today()->subYear()->toDateString(),
                ],
            );
        }

        foreach ($employees->random(min(6, $employees->count())) as $employee) {
            EmployeeLoan::firstOrCreate(
                ['employee_id' => $employee->id, 'type' => 'sss'],
                [
                    'reference_number' => 'SSS-'.fake()->numerify('########'),
                    'principal_amount' => 24000,
                    'monthly_amortization' => 2000,
                    'outstanding_balance' => 24000,
                    'start_date' => Carbon::today()->subMonths(3)->toDateString(),
                    'status' => EmployeeLoan::STATUS_ACTIVE,
                ],
            );
        }
    }
}
