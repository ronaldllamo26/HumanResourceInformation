<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
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
     * The inputs for one employee's payslip, drawn from Modules 2 and 3.
     *
     * @return array<string, mixed>
     */
    public function gatherInputs(PayrollPeriod $period, Employee $employee): array
    {
        $from = $period->start_date;
        $to = $period->end_date;

        $attendance = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('coalesce(sum(hours_worked), 0) as hours')
            ->selectRaw('coalesce(sum(late_minutes), 0) as late')
            ->selectRaw('coalesce(sum(undertime_minutes), 0) as undertime')
            ->selectRaw('coalesce(sum(night_diff_minutes), 0) as night_diff')
            ->selectRaw("coalesce(sum(case when status in ('present','late','undertime') then 1 else 0 end), 0) as days_worked")
            ->first();

        return [
            // The rate in force over the period being paid, not the rate the
            // employee is on today: a raise keyed in after the fact must not
            // rewrite a period that closed before it took effect.
            'monthly_salary' => $this->salaries->rateAsOf($employee, $to),
            'pay_frequency' => $period->frequency,

            'days_worked' => (float) $attendance->days_worked,
            'hours_worked' => round((float) $attendance->hours, 2),
            // Raw time past the shift is recorded by attendance, but only
            // *approved* overtime is paid.
            'overtime_hours' => $this->approvedOvertimeHours($employee, $from, $to),
            'night_diff_hours' => round(((float) $attendance->night_diff) / 60, 2),
            'late_minutes' => (int) $attendance->late,
            'undertime_minutes' => (int) $attendance->undertime,
            'absent_days' => $this->unexcusedAbsentDays($employee, $from, $to),
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
     * Absent days with no approved leave behind them — the days that are
     * genuinely unpaid because nobody authorised them.
     *
     * This used to be a plain count of DTR rows marked `absent`, and it was
     * wrong in both directions at once:
     *
     * - **Approved unpaid leave was deducted twice.** The day counted here as
     *   an absence *and* again in `unpaid_leave_days`, so a week of authorised
     *   leave without pay cost the employee two weeks of salary.
     * - **Approved paid leave was deducted at all.** A VL day is already
     *   inside the basic salary — that is what "paid leave" means — so taking
     *   it off again docked somebody for leave they were entitled to.
     *
     * Both were invisible from the payslip, which shows "Absences" and
     * "Unpaid leave" as separate lines that each looked individually correct.
     *
     * Whether a day is covered is asked of LeaveService rather than derived
     * here, so payroll, the exception scanner and the DTR screen cannot come
     * to different conclusions about the same Tuesday.
     */
    public function unexcusedAbsentDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $absentDates = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', AttendanceLog::STATUS_ABSENT)
            ->pluck('log_date');

        if ($absentDates->isEmpty()) {
            return 0.0;
        }

        $covered = $this->leave->approvedLeaveDates([$employee->id], $from, $to);

        return (float) $absentDates
            ->reject(fn ($date) => $covered->has(
                $employee->id.'|'.Carbon::parse($date)->toDateString(),
            ))
            ->count();
    }

    /** Approved overtime hours falling inside the period. */
    public function approvedOvertimeHours(Employee $employee, Carbon $from, Carbon $to): float
    {
        return round((float) OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('hours'), 2);
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
