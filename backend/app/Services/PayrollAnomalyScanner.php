<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * Looks at a computed run for the patterns payroll fraud and payroll mistakes
 * leave behind, before anybody approves it.
 *
 * `PayrollReadinessChecker` asks whether the *time records* are fit to pay
 * from; this asks whether the *money* looks right once it has been computed.
 * Each check is a known way a run goes wrong:
 *
 *  - critical — somebody is being paid who should not be, or a payslip's
 *    figures do not add up. These are what a ghost employee or an edited
 *    payslip look like.
 *  - warning — probably legitimate, but a person should confirm it.
 *
 * Like the readiness panel, nothing here stops a run — the approver decides,
 * with the findings on the screen in front of them instead of hidden in it.
 */
class PayrollAnomalyScanner
{
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_WARNING = 'warning';

    /** Net pay moving by more than this share against the last paid run is flagged. */
    public const NET_PAY_SWING = 0.5;

    /**
     * @return array{clean: bool, critical: int, warnings: int, findings: array<int, array<string, mixed>>}
     */
    public function scan(PayrollRun $run): array
    {
        $run->loadMissing('period');

        $payslips = Payslip::query()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->get();

        $findings = collect([
            $this->sharedBankAccounts($payslips),
            $this->paidAfterLeaving($run, $payslips),
            $this->hiredAfterPeriod($run, $payslips),
            $this->figuresThatDoNotAddUp($payslips),
            $this->nonPositiveNetPay($payslips),
            $this->largeNetPaySwings($run, $payslips),
        ])->filter()->values();

        $critical = $findings->where('severity', self::SEVERITY_CRITICAL)->count();

        return [
            'clean' => $findings->isEmpty(),
            'critical' => $critical,
            'warnings' => $findings->count() - $critical,
            'findings' => $findings->all(),
        ];
    }

    /**
     * Two people paid into one bank account — the classic ghost employee: an
     * invented record whose salary lands in a real person's account.
     * Compared in PHP because the column is encrypted.
     */
    private function sharedBankAccounts(Collection $payslips): ?array
    {
        $groups = $payslips
            ->filter(fn (Payslip $payslip) => filled($payslip->employee?->bank_account_number))
            ->groupBy(fn (Payslip $payslip) => preg_replace('/\D/', '', (string) $payslip->employee->bank_account_number))
            ->filter(fn (Collection $group, string $account) => $account !== '' && $group->count() > 1);

        if ($groups->isEmpty()) {
            return null;
        }

        $names = $groups->flatten()->map(fn (Payslip $payslip) => $payslip->employee->full_name);

        return $this->finding(
            'shared_bank_account',
            self::SEVERITY_CRITICAL,
            'Two or more employees are paid into the same bank account',
            "{$groups->count()} account(s) receive more than one salary. One of these records may not be a real employee.",
            $names,
        );
    }

    /** Paid although the record says they have resigned, been terminated or archived. */
    private function paidAfterLeaving(PayrollRun $run, Collection $payslips): ?array
    {
        $periodStart = $run->period?->start_date;

        $left = $payslips->filter(function (Payslip $payslip) use ($periodStart) {
            $employee = $payslip->employee;

            if (! $employee) {
                return false;
            }

            if ($employee->trashed() || in_array($employee->employment_status, Employee::SEPARATED_STATUSES, true)) {
                return true;
            }

            // Separated before this period even began.
            return $employee->date_separated !== null
                && $periodStart !== null
                && $employee->date_separated->lt($periodStart);
        });

        if ($left->isEmpty()) {
            return null;
        }

        return $this->finding(
            'paid_after_leaving',
            self::SEVERITY_CRITICAL,
            'Paid although the record says they have left',
            "{$left->count()} payslip(s) belong to employees marked resigned, terminated, archived, or separated before this period. Their final pay goes through Separation & Final Pay instead.",
            $left->map(fn (Payslip $payslip) => $payslip->employee->full_name),
        );
    }

    private function hiredAfterPeriod(PayrollRun $run, Collection $payslips): ?array
    {
        $periodEnd = $run->period?->end_date;

        if ($periodEnd === null) {
            return null;
        }

        $early = $payslips->filter(
            fn (Payslip $payslip) => $payslip->employee?->date_hired !== null
                && $payslip->employee->date_hired->gt($periodEnd),
        );

        if ($early->isEmpty()) {
            return null;
        }

        return $this->finding(
            'hired_after_period',
            self::SEVERITY_CRITICAL,
            'Paid for a period before they were hired',
            "{$early->count()} employee(s) have a hire date after this period ended.",
            $early->map(fn (Payslip $payslip) => $payslip->employee->full_name),
        );
    }

    /**
     * Net pay must equal gross minus deductions. The calculator always makes it
     * so, which is exactly why a payslip where it does not was edited after
     * the fact. A centavo of tolerance, as `meta.balanced` uses.
     */
    private function figuresThatDoNotAddUp(Collection $payslips): ?array
    {
        $broken = $payslips->filter(
            fn (Payslip $payslip) => abs(
                ((float) $payslip->gross_pay - (float) $payslip->deductions_total) - (float) $payslip->net_pay,
            ) > 0.01,
        );

        if ($broken->isEmpty()) {
            return null;
        }

        return $this->finding(
            'figures_do_not_add_up',
            self::SEVERITY_CRITICAL,
            'Payslips whose net pay is not gross minus deductions',
            "{$broken->count()} payslip(s) do not add up — a sign a figure was changed outside the calculator. Recompute the run.",
            $broken->map(fn (Payslip $payslip) => $payslip->employee?->full_name),
        );
    }

    private function nonPositiveNetPay(Collection $payslips): ?array
    {
        $zero = $payslips->filter(fn (Payslip $payslip) => (float) $payslip->net_pay <= 0);

        if ($zero->isEmpty()) {
            return null;
        }

        return $this->finding(
            'non_positive_net_pay',
            self::SEVERITY_WARNING,
            'Zero or negative net pay',
            "{$zero->count()} employee(s) take home nothing this period — usually deductions or absences larger than pay.",
            $zero->map(fn (Payslip $payslip) => $payslip->employee?->full_name),
        );
    }

    /** Net pay far off the same person's last approved or paid payslip. */
    private function largeNetPaySwings(PayrollRun $run, Collection $payslips): ?array
    {
        $employeeIds = $payslips->pluck('employee_id')->all();

        $previous = Payslip::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_run_id', '!=', $run->id)
            ->whereHas('run', fn ($query) => $query->reportable())
            ->orderByDesc('payroll_run_id')
            ->get(['employee_id', 'net_pay', 'payroll_run_id'])
            ->unique('employee_id')
            ->keyBy('employee_id');

        $swings = $payslips->filter(function (Payslip $payslip) use ($previous) {
            $before = (float) ($previous->get($payslip->employee_id)?->net_pay ?? 0);

            if ($before <= 0) {
                return false;
            }

            return abs((float) $payslip->net_pay - $before) / $before > self::NET_PAY_SWING;
        });

        if ($swings->isEmpty()) {
            return null;
        }

        $percent = (int) (self::NET_PAY_SWING * 100);

        return $this->finding(
            'net_pay_swing',
            self::SEVERITY_WARNING,
            "Net pay changed by more than {$percent}% since the last payroll",
            "{$swings->count()} employee(s). Often a raise, unpaid leave or a loan ending — worth confirming before approving.",
            $swings->map(fn (Payslip $payslip) => $payslip->employee?->full_name),
        );
    }

    /** @return array<string, mixed> */
    private function finding(string $key, string $severity, string $title, string $detail, Collection $names): array
    {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'employees' => $names->filter()->unique()->sort()->take(8)->values()->all(),
        ];
    }
}
