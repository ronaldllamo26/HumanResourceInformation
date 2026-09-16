<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 4 — payroll run orchestration.
 *
 * Gathers each employee's period figures from Timekeeping and Leave, hands them
 * to PayrollCalculator, and stores the resulting payslips. Loans are only
 * amortised when a run is approved, so a draft can be recomputed freely.
 */
class PayrollService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly EmployeeService $employees,
        private readonly SalaryAdjustmentService $salaries,
        private readonly LeaveService $leave,
        private readonly TimekeepingService $timekeeping,
    ) {}

    /** Payslips the viewer may see — employees see only their own. */
    public function scopedPayslipQuery(User $user): Builder
    {
        return Payslip::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'run.period'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Computes a draft run for a period, replacing any existing draft.
     * Recomputing is safe and expected — figures change as HR corrects DTRs.
     */
    public function generate(PayrollPeriod $period, User $processor): PayrollRun
    {
        return DB::transaction(function () use ($period, $processor) {
            // Only ever one draft per period; a fresh compute supersedes it.
            $period->runs()->where('status', PayrollRun::STATUS_DRAFT)->each(
                fn (PayrollRun $existing) => $existing->delete(),
            );

            $run = $period->runs()->create([
                'run_number' => $this->nextRunNumber(),
                'status' => PayrollRun::STATUS_DRAFT,
                'processed_by' => $processor->id,
                'processed_at' => now(),
            ]);

            $employees = Employee::query()
                ->where('status', '!=', 'inactive')
                ->where('basic_salary', '>', 0)
                ->get();

            foreach ($employees as $employee) {
                $this->createPayslip($run, $period, $employee);
            }

            return $this->refreshTotals($run);
        });
    }

    /** Draft → for approval. */
    public function submitForApproval(PayrollRun $run): PayrollRun
    {
        $run->update(['status' => PayrollRun::STATUS_FOR_APPROVAL]);

        return $run->refresh();
    }

    /**
     * Approves the run and applies loan amortisations. This is the point of no
     * return: balances move, so a run can no longer be recomputed.
     */
    public function approve(PayrollRun $run, User $approver, ?string $remarks = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $approver, $remarks) {
            $run->update([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'remarks' => $remarks,
            ]);

            $this->amortiseLoans($run);

            $run->period->update(['status' => PayrollPeriod::STATUS_APPROVED]);

            return $run->refresh();
        });
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run->update(['status' => PayrollRun::STATUS_PAID]);
            $run->period->update(['status' => PayrollPeriod::STATUS_PAID]);

            return $run->refresh();
        });
    }

    public function cancel(PayrollRun $run, ?string $remarks = null): PayrollRun
    {
        $run->update([
            'status' => PayrollRun::STATUS_CANCELLED,
            'remarks' => $remarks,
        ]);

        return $run->refresh();
    }

    /** Company-wide totals for the run header and the runs list. */
    public function refreshTotals(PayrollRun $run): PayrollRun
    {
        $totals = $run->payslips()
            ->selectRaw('count(*) as employee_count')
            ->selectRaw('coalesce(sum(gross_pay), 0) as gross')
            ->selectRaw('coalesce(sum(deductions_total), 0) as deductions')
            ->selectRaw('coalesce(sum(net_pay), 0) as net')
            ->first();

        $run->update([
            'employee_count' => (int) $totals->employee_count,
            'total_gross' => round((float) $totals->gross, 2),
            'total_deductions' => round((float) $totals->deductions, 2),
            'total_net' => round((float) $totals->net, 2),
        ]);

        return $run->refresh();
    }

    /**
     * The inputs for one employee's payslip.
     *
     * Time & Attendance was removed pending a redesign, so nothing
     * attendance-derived reaches the calculator: no days worked, lateness,
     * undertime, unexcused absence, overtime, night differential or holiday
     * premium. Everyone is paid their basic salary for the period, less approved
     * unpaid leave, plus allowances, less loans and adjustments. The calculator
     * still accepts those inputs, so the new submodules only have to supply
     * them here again.
     *
     * @return array<string, mixed>
     */
    public function gatherInputs(PayrollPeriod $period, Employee $employee): array
    {
        $from = $period->start_date;
        $to = $period->end_date;

        // The rate in force over the period being paid, not the rate the
        // employee is on today: a raise keyed in after the fact must not
        // rewrite a period that closed before it took effect.
        $monthlySalary = $this->salaries->rateAsOf($employee, $to);

        // Time & Attendance's totals: absences already exclude days an
        // approved leave covers, and overtime is approved requests only.
        $attendance = $this->timekeeping->summaryFor($employee, $from, $to);

        return [
            'monthly_salary' => $monthlySalary,
            'pay_frequency' => $period->frequency,

            'days_worked' => (float) $attendance['days_worked'],
            'hours_worked' => round($attendance['minutes_worked'] / 60, 2),
            'overtime_hours' => (float) $attendance['overtime_hours'],
            'night_diff_hours' => round($attendance['night_diff_minutes'] / 60, 2),
            'late_minutes' => (int) $attendance['late_minutes'],
            'undertime_minutes' => (int) $attendance['undertime_minutes'],
            'absent_days' => (float) $attendance['absent_days'],
            'holiday_pay' => $this->holidayPremium($monthlySalary, $attendance),
            'unpaid_leave_days' => $this->unpaidLeaveDays($employee, $from, $to),

            'allowances' => $this->allowances($employee, $period),
            'loans' => $this->loans($employee, $period),

            /*
             * One-off amounts another system put on this payslip — Fleet's
             * trip allowances, Supply Chain's damage deductions.
             *
             * **Summed here, at compute time, and that is the whole safety
             * property.** A draft run can be recomputed freely; an endpoint
             * that added an amount to a payslip when it was *called* would add
             * it again on the next recompute. Reading stored rows instead means
             * recomputing reaches the same total — the same reason `loans()`
             * above is a read rather than a ledger entry.
             */
            'other_deductions' => $this->externalDeductions($employee, $period),
        ];
    }

    /**
     * The Labor Code premium for work on a holiday, on top of the day the
     * monthly salary already pays: +100% of the hourly rate on a regular
     * holiday (×2.0 in all), +30% on a special non-working day (×1.3).
     *
     * @param  array<string, float|int>  $attendance
     */
    public function holidayPremium(float $monthlySalary, array $attendance): float
    {
        $hourly = $this->calculator->rates($monthlySalary)['hourly'];
        $premiums = config('payroll.premiums');

        return round(
            $hourly * ($premiums['regular_holiday'] - 1) * (float) ($attendance['regular_holiday_hours'] ?? 0)
            + $hourly * ($premiums['special_holiday'] - 1) * (float) ($attendance['special_holiday_hours'] ?? 0),
            2,
        );
    }

    /**
     * Days of approved *unpaid* leave inside the period. Paid leave is already
     * covered by the basic salary and must not be deducted.
     */
    public function unpaidLeaveDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get()
            ->filter(fn (LeaveRequest $request) => ! $request->leaveType?->is_paid);

        $days = 0.0;

        foreach ($requests as $request) {
            // A request can straddle the period boundary; count only the part
            // that falls inside it.
            $start = $request->start_date->copy()->max($from);
            $end = $request->end_date->copy()->min($to);

            $spanned = $start->diffInDays($end) + 1;
            $total = $request->start_date->diffInDays($request->end_date) + 1;

            $days += (float) $request->days_requested * ($spanned / max($total, 1));
        }

        return round($days, 2);
    }

    private function createPayslip(PayrollRun $run, PayrollPeriod $period, Employee $employee): void
    {
        $computed = $this->calculator->compute($this->gatherInputs($period, $employee));

        $payslip = $run->payslips()->create([
            ...$computed['payslip'],
            'employee_id' => $employee->id,
            'payslip_number' => sprintf('PS-%s-%04d', $run->run_number, $employee->id),
        ]);

        foreach ($computed['lines'] as $line) {
            $payslip->lines()->create($line);
        }
    }

    /**
     * Standing allowances, plus the one-off earnings another system posted for
     * this cutoff.
     *
     * The two are different in kind and belong in one list: a rice allowance
     * recurs and is prorated by frequency, while a trip allowance was earned
     * once in this fortnight and is paid at face value. `amountForPeriod()`
     * applies to the first and must not touch the second — halving a ₱500 trip
     * allowance because the run is semi-monthly would pay ₱250 for a trip that
     * happened.
     *
     * @return array<int, array{label: string, amount: float, taxable: bool}>
     */
    private function allowances(Employee $employee, PayrollPeriod $period): array
    {
        $standing = EmployeeAllowance::where('employee_id', $employee->id)
            ->effectiveOn($period->end_date)
            ->get()
            ->map(fn (EmployeeAllowance $allowance) => [
                'label' => $allowance->name,
                'amount' => $allowance->amountForPeriod($period->frequency),
                'taxable' => (bool) $allowance->is_taxable,
            ]);

        $oneOff = PayrollAdjustment::where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->earnings()
            ->get()
            ->map(fn (PayrollAdjustment $adjustment) => [
                // The source is on the line, so a payslip says who decided it
                // rather than leaving somebody to ask HR.
                'label' => $adjustment->sourceLabel().' — '.$adjustment->label,
                'amount' => (float) $adjustment->amount,
                'taxable' => (bool) $adjustment->is_taxable,
            ]);

        return $standing->concat($oneOff)->values()->all();
    }

    /**
     * One-off deductions another system posted for this cutoff.
     *
     * Supply Chain's accountability for a damaged or lost item, and anything
     * else that is neither a statutory withholding nor a loan. These land in
     * the calculator's `other_deductions` slot, which had been built and never
     * fed until an external system needed it.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    private function externalDeductions(Employee $employee, PayrollPeriod $period): array
    {
        return PayrollAdjustment::where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->deductions()
            ->get()
            ->map(fn (PayrollAdjustment $adjustment) => [
                'label' => $adjustment->sourceLabel().' — '.$adjustment->label,
                'amount' => (float) $adjustment->amount,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array{label: string, amount: float, loan_id: int}> */
    private function loans(Employee $employee, PayrollPeriod $period): array
    {
        return EmployeeLoan::where('employee_id', $employee->id)
            ->active()
            ->get()
            ->map(fn (EmployeeLoan $loan) => [
                'label' => strtoupper(str_replace('_', ' ', $loan->type)).' Loan',
                'amount' => $loan->amortisationForPeriod($period->frequency),
                'loan_id' => $loan->id,
            ])
            ->filter(fn (array $loan) => $loan['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Applies each payslip's loan deduction against the outstanding balance.
     * Runs once, at approval — never while the run is still a draft.
     */
    private function amortiseLoans(PayrollRun $run): void
    {
        $payslips = $run->payslips()->where('loans_deduction', '>', 0)->get();

        foreach ($payslips as $payslip) {
            $remaining = (float) $payslip->loans_deduction;

            $loans = EmployeeLoan::where('employee_id', $payslip->employee_id)
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
    }

    private function nextRunNumber(): string
    {
        $year = now()->year;
        $prefix = "PR-{$year}-";

        $latest = PayrollRun::where('run_number', 'like', $prefix.'%')
            ->orderByDesc('run_number')
            ->value('run_number');

        $sequence = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
