<?php

namespace App\Console\Commands;

use App\Services\SalaryAdjustmentService;
use Illuminate\Console\Command;

/**
 * Brings every employee's cached `basic_salary` up to the rate in force today,
 * which is how a future-dated adjustment takes effect on its own date.
 *
 * Safe to run repeatedly: the service recomputes rather than adds, so there is
 * no "already applied" state to keep in step. Money does not depend on this —
 * payroll and final pay read the history directly — so a missed run costs a
 * stale figure on a form, never a wrong payslip.
 */
class ApplyDueSalaryAdjustments extends Command
{
    protected $signature = 'salaries:apply-due';

    protected $description = 'Point each employee\'s salary at the rate in force today';

    public function handle(SalaryAdjustmentService $salaries): int
    {
        $moved = $salaries->syncDue();

        $this->info($moved === 0
            ? 'Every salary already matches the rate in force.'
            : "Updated {$moved} employee salary/salaries.");

        return self::SUCCESS;
    }
}
