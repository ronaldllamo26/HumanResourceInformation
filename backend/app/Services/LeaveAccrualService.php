<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brings every balance up to what service has actually earned.
 *
 * Safe to re-run: it recomputes `credits_earned` from the hire date each time
 * rather than adding to it, so running it twice in a month grants nothing
 * twice. That also means it is the whole accrual mechanism — there is no
 * separate "already accrued this month" state to keep in step.
 *
 * `credits_used` and `credits_carried_over` are never touched. Accrual
 * decides what was earned; it has no business editing what was spent.
 */
class LeaveAccrualService
{
    public function __construct(private readonly LeaveAccrualCalculator $calculator) {}

    /**
     * @return array{
     *     updated: int,
     *     unchanged: int,
     *     over_granted: array<int, array<string, mixed>>
     * }
     */
    public function accrue(int $year, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();

        $types = LeaveType::where('is_active', true)
            ->where('default_credits', '>', 0)
            ->get();

        $employees = Employee::where('status', '!=', 'inactive')
            ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'date_hired']);

        $updated = 0;
        $unchanged = 0;
        $overGranted = [];

        DB::transaction(function () use (
            $types, $employees, $year, $asOf, &$updated, &$unchanged, &$overGranted
        ) {
            foreach ($employees as $employee) {
                foreach ($types as $type) {
                    $earned = $this->calculator->earned(
                        (float) $type->default_credits,
                        $employee->date_hired,
                        $year,
                        $asOf,
                    );

                    $balance = LeaveBalance::firstOrNew([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                    ]);

                    $used = (float) ($balance->credits_used ?? 0);

                    // Someone may already have filed against a bulk allocation
                    // that accrual would now undercut. Dropping earned below
                    // used would invent a negative balance and, worse, imply
                    // the approved leave was never valid — so hold the floor
                    // at what was spent and report it instead.
                    if ($earned < $used) {
                        $overGranted[] = [
                            'employee' => $employee->full_name,
                            'employee_number' => $employee->employee_number,
                            'leave_type' => $type->name,
                            'accrued' => $earned,
                            'already_used' => $used,
                        ];

                        $earned = $used;
                    }

                    if ($balance->exists && (float) $balance->credits_earned === $earned) {
                        $unchanged++;

                        continue;
                    }

                    $balance->credits_earned = $earned;
                    $balance->save();

                    $updated++;
                }
            }
        });

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'over_granted' => $overGranted,
        ];
    }
}
