<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveBalance;
use App\Models\Payslip;
use App\Models\Separation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 — the exit half of the employee lifecycle.
 *
 * Gathers what the employee is owed from the modules that already know it —
 * payroll for what was paid, leave for what was not taken, compensation for
 * what is still borrowed — hands it to FinalPayCalculator, and stores the
 * result as a snapshot.
 *
 * Snapshotted, not recomputed on read, for the same reason payslips are: a
 * later change to salary, leave credits, or a loan balance must not silently
 * rewrite a settlement already handed over.
 */
class SeparationService
{
    public function __construct(
        private readonly FinalPayCalculator $calculator,
        private readonly SalaryAdjustmentService $salaries,
    ) {}

    /** Opens a separation and computes the settlement from live figures. */
    public function open(Employee $employee, array $data, User $processor): Separation
    {
        return DB::transaction(function () use ($employee, $data, $processor) {
            $lastDay = Carbon::parse($data['last_day']);
            $computed = $this->calculator->compute($this->gatherInputs($employee, $lastDay, $data));

            return Separation::create([
                'employee_id' => $employee->id,
                'last_day' => $lastDay->toDateString(),
                'reason' => $data['reason'],
                'status' => Separation::STATUS_DRAFT,
                'unpaid_salary' => $computed['unpaid_salary'],
                'thirteenth_month' => $computed['thirteenth_month'],
                'leave_conversion' => $computed['leave_conversion'],
                'loan_deduction' => $computed['loan_deduction'],
                'other_deductions' => $computed['other_deductions'],
                'net_final_pay' => $computed['net_final_pay'],
                'breakdown' => $computed['lines'],
                'clearance' => $this->blankChecklist(),
                'remarks' => $data['remarks'] ?? null,
                'processed_by' => $processor->id,
            ]);
        });
    }

    /** Recomputes a draft against current figures. */
    public function recompute(Separation $separation, array $data = []): Separation
    {
        $employee = $separation->employee;
        $computed = $this->calculator->compute(
            $this->gatherInputs($employee, $separation->last_day, $data),
        );

        $separation->update([
            'unpaid_salary' => $computed['unpaid_salary'],
            'thirteenth_month' => $computed['thirteenth_month'],
            'leave_conversion' => $computed['leave_conversion'],
            'loan_deduction' => $computed['loan_deduction'],
            'other_deductions' => $computed['other_deductions'],
            'net_final_pay' => $computed['net_final_pay'],
            'breakdown' => $computed['lines'],
        ]);

        return $separation->refresh();
    }

    /** Marks one clearance item settled, or un-settles it. */
    public function toggleClearance(Separation $separation, string $key, bool $cleared): Separation
    {
        $checklist = collect($separation->clearance ?? [])
            ->map(function (array $item) use ($key, $cleared) {
                if ($item['key'] === $key) {
                    $item['cleared_at'] = $cleared ? now()->toIso8601String() : null;
                }

                return $item;
            })
            ->all();

        $separation->update([
            'clearance' => $checklist,
            // Status follows the checklist rather than being set by hand, so
            // the two cannot disagree.
            'status' => $separation->fresh()->status === Separation::STATUS_RELEASED
                ? Separation::STATUS_RELEASED
                : (collect($checklist)->where('blocking', true)
                    ->every(fn (array $item) => filled($item['cleared_at']))
                        ? Separation::STATUS_CLEARED
                        : Separation::STATUS_DRAFT),
        ]);

        return $separation->refresh();
    }

    /**
     * Releases the settlement. This is the point of no return: the employee
     * is marked separated, their loans are closed against the deduction, and
     * the figures are frozen.
     */
    public function release(Separation $separation): Separation
    {
        return DB::transaction(function () use ($separation) {
            $separation->update([
                'status' => Separation::STATUS_RELEASED,
                'released_at' => now(),
            ]);

            $this->settleLoans($separation);

            $separation->employee->update([
                'employment_status' => $separation->reason === Separation::REASON_TERMINATED
                    ? 'terminated'
                    : 'resigned',
                'status' => 'inactive',
            ]);

            return $separation->refresh();
        });
    }

    /**
     * What the other modules know about this employee.
     *
     * @return array<string, float>
     */
    public function gatherInputs(Employee $employee, Carbon $lastDay, array $data = []): array
    {
        return [
            // The rate they were on at separation, which is not necessarily
            // the rate on file today — a scheduled raise they never worked to
            // see must not inflate the settlement.
            'monthly_salary' => $this->salaries->rateAsOf($employee, $lastDay),
            'days_unpaid' => (float) ($data['days_unpaid'] ?? 0),
            'basic_earned_this_year' => $this->basicEarnedThisYear($employee, $lastDay),
            'convertible_leave_days' => $this->convertibleLeaveDays($employee, $lastDay->year),
            'loan_balance' => $this->loanBalance($employee),
            'other_deductions' => (float) ($data['other_deductions'] ?? 0),
        ];
    }

    /**
     * Basic salary actually earned this year — the same definition the
     * 13th-month screen uses, so the two figures agree.
     *
     * `reportable()` is what makes that true: without it a settlement would
     * pay 13th month on payslips from a draft run that is still being
     * corrected, and quote a figure the 13th-month screen does not show.
     */
    private function basicEarnedThisYear(Employee $employee, Carbon $lastDay): float
    {
        $earned = Payslip::where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query
                ->reportable()
                ->whereHas('period', fn ($inner) => $inner->whereYear('end_date', $lastDay->year)),
            )
            ->get()
            ->sum(fn (Payslip $slip) => (float) $slip->basic_pay
                - (float) $slip->late_deduction
                - (float) $slip->undertime_deduction
                - (float) $slip->absence_deduction
                - (float) $slip->unpaid_leave_deduction,
            );

        return round(max((float) $earned, 0), 2);
    }

    /** Unused credits on leave types the company converts to cash. */
    private function convertibleLeaveDays(Employee $employee, int $year): float
    {
        $days = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->whereHas('leaveType', fn ($query) => $query->where('is_convertible_to_cash', true))
            ->get()
            ->sum(fn (LeaveBalance $balance) => (float) $balance->credits_earned
                + (float) $balance->credits_carried_over
                - (float) $balance->credits_used,
            );

        return round(max((float) $days, 0), 2);
    }

    private function loanBalance(Employee $employee): float
    {
        return round(
            (float) EmployeeLoan::where('employee_id', $employee->id)->active()->sum('outstanding_balance'),
            2,
        );
    }

    /** Applies the settlement's loan deduction against the balances. */
    private function settleLoans(Separation $separation): void
    {
        $remaining = (float) $separation->loan_deduction;

        if ($remaining <= 0) {
            return;
        }

        $loans = EmployeeLoan::where('employee_id', $separation->employee_id)
            ->active()
            ->orderBy('start_date')
            ->get();

        foreach ($loans as $loan) {
            if ($remaining <= 0) {
                break;
            }

            $applied = min($remaining, (float) $loan->outstanding_balance);
            $balance = round((float) $loan->outstanding_balance - $applied, 2);

            $loan->update([
                'outstanding_balance' => $balance,
                'status' => $balance <= 0 ? EmployeeLoan::STATUS_PAID : EmployeeLoan::STATUS_ACTIVE,
            ]);

            $remaining = round($remaining - $applied, 2);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function blankChecklist(): array
    {
        return collect(config('separation.checklist', []))
            ->map(fn (array $item, string $key) => [
                'key' => $key,
                'label' => $item['label'],
                'blocking' => (bool) $item['blocking'],
                'cleared_at' => null,
            ])
            ->values()
            ->all();
    }
}
