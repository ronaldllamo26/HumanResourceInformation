<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SalaryAdjustment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where an employee's rate is set.
 *
 * `employees.basic_salary` stays, but it is now a *cache of today's rate*, not
 * the record. The record is the adjustment history, and `rateAsOf()` is what
 * money reads — because the two answer different questions:
 *
 *   basic_salary  "what does this employee earn now?"      (forms, directory)
 *   rateAsOf()    "what were they on over this period?"    (payroll, final pay)
 *
 * Keeping them apart is what stops a raise keyed in today from quietly
 * rewriting a payroll run for a period that closed before it took effect.
 */
class SalaryAdjustmentService
{
    /**
     * The rate in force on a given date.
     *
     * Falls back in two steps, and the order matters:
     *
     *  1. The newest adjustment effective on or before the date.
     *  2. If the employee has adjustments but all of them are *later* than the
     *     date, the earliest one's `previous_salary` — the rate they were on
     *     before the first recorded change. Reaching for `basic_salary` here
     *     would hand back today's raised figure for a period that predates it.
     *  3. Only an employee with no history at all falls back to
     *     `basic_salary`, which is every record seeded or created before this
     *     screen existed.
     */
    public function rateAsOf(Employee $employee, Carbon $date): float
    {
        $inForce = SalaryAdjustment::where('employee_id', $employee->id)
            ->effectiveBy($date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($inForce) {
            return (float) $inForce->new_salary;
        }

        $earliest = SalaryAdjustment::where('employee_id', $employee->id)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->first();

        if ($earliest) {
            return (float) $earliest->previous_salary;
        }

        return (float) $employee->basic_salary;
    }

    /**
     * Records a rate change.
     *
     * `previous_salary` is taken from the rate in force on the effective date,
     * not from whatever the employee's field happens to say — otherwise
     * back-dating an adjustment would record a "from" figure that was never
     * true on that date.
     */
    public function record(Employee $employee, array $data, User $approver): SalaryAdjustment
    {
        return DB::transaction(function () use ($employee, $data, $approver) {
            $effective = Carbon::parse($data['effective_date']);

            $adjustment = SalaryAdjustment::create([
                'employee_id' => $employee->id,
                'previous_salary' => $this->rateAsOf($employee, $effective),
                'new_salary' => round((float) $data['new_salary'], 2),
                'effective_date' => $effective->toDateString(),
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'approved_by' => $approver->id,
            ]);

            $this->syncCurrent($employee);

            return $adjustment;
        });
    }

    /**
     * Removes an adjustment and puts the rate back where it was.
     *
     * The `previous_salary` has to be read *before* the delete, and applied
     * directly when nothing is left: with an empty history `rateAsOf()` falls
     * back to `basic_salary`, which still holds the very rate this adjustment
     * set — so removing an employee's only adjustment would quietly keep the
     * raise it was meant to undo.
     */
    public function delete(SalaryAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $employee = $adjustment->employee;
            $restore = (float) $adjustment->previous_salary;

            $adjustment->delete();

            if (! SalaryAdjustment::where('employee_id', $employee->id)->exists()) {
                $employee->forceFill(['basic_salary' => $restore])->save();

                return;
            }

            $this->syncCurrent($employee);
        });
    }

    /**
     * Points `basic_salary` at today's rate.
     *
     * Recomputes rather than adds, the same way leave accrual does, so it is
     * safe to run at any time and needs no "already applied" flag to keep in
     * step. A scheduled raise lands here on the day it takes effect.
     */
    public function syncCurrent(Employee $employee): float
    {
        $rate = $this->rateAsOf($employee, Carbon::today());

        if ((float) $employee->basic_salary !== $rate) {
            $employee->forceFill(['basic_salary' => $rate])->save();
        }

        return $rate;
    }

    /**
     * Brings every employee's cached rate up to date.
     *
     * This is what makes a future-dated adjustment take effect on its own.
     * Money never depends on it — payroll reads `rateAsOf()` directly — so a
     * day where this does not run costs a stale figure on a form, not a wrong
     * payslip.
     *
     * @return int employees whose rate moved
     */
    public function syncDue(): int
    {
        $employeeIds = SalaryAdjustment::effectiveBy(Carbon::today())
            ->distinct()
            ->pluck('employee_id');

        $moved = 0;

        foreach (Employee::whereIn('id', $employeeIds)->cursor() as $employee) {
            $before = (float) $employee->basic_salary;

            if ($this->syncCurrent($employee) !== $before) {
                $moved++;
            }
        }

        return $moved;
    }
}
