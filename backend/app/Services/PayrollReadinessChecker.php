<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

/**
 * Module 2 → Module 4 — is the DTR clean enough to pay from?
 *
 * PayrollService::gatherInputs() reads attendance without judging it, so a
 * forgotten time-out quietly understates an employee's hours and a pending
 * overtime request quietly pays nothing. Both are silent: the run computes,
 * the totals look plausible, and the error only surfaces when someone opens
 * their payslip. This runs the same checks *before* the money is computed.
 *
 * Severity is a workflow decision, not a data one:
 *
 *  - blocker — paying from this would be wrong (a day with no time-out has
 *    no hours behind it). HR should fix the DTR first.
 *  - warning — payable, but someone should have decided already (a pending
 *    overtime request pays zero unless approved before the run).
 *
 * Nothing here hard-stops a run. HR may have a reason, and a payroll that
 * cannot be run is worse than one that warns loudly — but the decision is
 * recorded on screen instead of being invisible.
 */
class PayrollReadinessChecker
{
    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        private readonly AttendanceExceptionScanner $scanner,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     blockers: int,
     *     warnings: int,
     *     checks: array<int, array<string, mixed>>
     * }
     */
    public function check(PayrollPeriod $period): array
    {
        $checks = collect([
            $this->missingTimeOuts($period),
            $this->pendingOvertime($period),
            $this->employeesWithoutAttendance($period),
            $this->ratesBelowRegionalMinimum(),
            $this->unservedSuspensions($period),
        ])->filter()->values();

        $blockers = $checks->where('severity', self::SEVERITY_BLOCKER)->count();

        return [
            'ready' => $checks->isEmpty(),
            'blockers' => $blockers,
            'warnings' => $checks->where('severity', self::SEVERITY_WARNING)->count(),
            'checks' => $checks->all(),
        ];
    }

    /**
     * Days clocked in but never out. The calculator has no end time to work
     * from, so the hours behind that day's pay are missing, not merely low.
     *
     * @return array<string, mixed>|null
     */
    private function missingTimeOuts(PayrollPeriod $period): ?array
    {
        $exceptions = $this->scanner
            ->scan(AttendanceLog::query()->filter([
                'from' => $period->start_date->toDateString(),
                'to' => $period->end_date->toDateString(),
            ]))
            ->where('type', AttendanceExceptionScanner::TYPE_MISSING_PUNCH);

        if ($exceptions->isEmpty()) {
            return null;
        }

        return $this->entry(
            'missing_time_outs',
            self::SEVERITY_BLOCKER,
            'Incomplete time records',
            "{$exceptions->count()} day(s) have a time-in with no time-out. Hours worked for those days are understated.",
            'Review in Timekeeping → Exceptions',
            '/hr/timekeeping/exceptions?type='.AttendanceExceptionScanner::TYPE_MISSING_PUNCH
                .'&from='.$period->start_date->toDateString()
                .'&to='.$period->end_date->toDateString(),
            $this->names($exceptions->pluck('employee_name')),
        );
    }

    /**
     * Overtime filed but not yet decided. Only approved overtime is paid, so
     * these pay nothing — which is correct only if that was deliberate.
     *
     * @return array<string, mixed>|null
     */
    private function pendingOvertime(PayrollPeriod $period): ?array
    {
        $pending = OvertimeRequest::with('employee:id,first_name,middle_name,last_name,suffix')
            ->where('status', OvertimeRequest::STATUS_PENDING)
            ->whereBetween('date', [
                $period->start_date->toDateString(),
                $period->end_date->toDateString(),
            ])
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        $hours = round((float) $pending->sum('hours'), 2);

        return $this->entry(
            'pending_overtime',
            self::SEVERITY_WARNING,
            'Undecided overtime',
            "{$pending->count()} overtime request(s) totalling {$hours}h are still pending. Only approved overtime is paid, so these will compute as zero.",
            'Decide in Timekeeping → Overtime',
            '/hr/timekeeping/overtime?status='.OvertimeRequest::STATUS_PENDING,
            $this->names($pending->map(fn (OvertimeRequest $request) => $request->employee?->full_name)),
        );
    }

    /**
     * Active, salaried employees with no DTR at all for the period. They are
     * still paid their basic salary, so this is silent by design — the person
     * may have been hired mid-period, or their biometrics may never have
     * imported.
     *
     * @return array<string, mixed>|null
     */
    private function employeesWithoutAttendance(PayrollPeriod $period): ?array
    {
        $missing = Employee::query()
            ->where('status', '!=', 'inactive')
            ->where('basic_salary', '>', 0)
            ->whereNotExists(function ($query) use ($period) {
                $query->selectRaw(1)
                    ->from('attendance_logs')
                    ->whereColumn('attendance_logs.employee_id', 'employees.id')
                    ->whereBetween('attendance_logs.log_date', [
                        $period->start_date->toDateString(),
                        $period->end_date->toDateString(),
                    ]);
            })
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix']);

        if ($missing->isEmpty()) {
            return null;
        }

        return $this->entry(
            'no_attendance',
            self::SEVERITY_WARNING,
            'No time records for the period',
            "{$missing->count()} employee(s) have no DTR in this period. They will be paid their basic salary with no attendance behind it.",
            'Review in Timekeeping → Daily Records',
            '/hr/timekeeping?from='.$period->start_date->toDateString()
                .'&to='.$period->end_date->toDateString(),
            $this->names($missing->map(fn (Employee $employee) => $employee->full_name)),
        );
    }

    /**
     * A handful of names, so the panel says who without becoming a report.
     *
     * @param  Collection<int, string|null>  $names
     * @return array<int, string>
     */
    /**
     * Daily rates sitting under the wage floor of the region the employee
     * actually works in.
     *
     * There is no national minimum wage in the Philippines: each region's
     * RTWPB issues its own order, which is what an agency's clients mean by a
     * "provincial rate". A driver deployed in Davao is measured against Davao's
     * floor, not Metro Manila's — so the check has to resolve the region per
     * employee rather than compare everyone to one number.
     *
     * A **warning, never a blocker**, for two reasons. A rate can sit under a
     * floor legitimately — a part-timer, an apprentice, a wage order the
     * config has not caught up with — and the figures in `payroll.wage_regions`
     * go stale the moment a board issues a new order. And refusing to run
     * payroll over it would strand the very employees it is meant to protect
     * unpaid, which is the wrong end of the problem.
     *
     * @return array<string, mixed>|null
     */
    private function ratesBelowRegionalMinimum(): ?array
    {
        $regions = config('payroll.wage_regions', []);
        $factor = (int) config('payroll.working_days_per_year', 261);

        if ($regions === [] || $factor <= 0) {
            return null;
        }

        $underpaid = Employee::query()
            ->where('status', '!=', 'inactive')
            ->where('basic_salary', '>', 0)
            ->with('client:id,wage_region')
            ->get()
            ->filter(function (Employee $employee) use ($regions, $factor) {
                $floor = $regions[$employee->wageRegion()]['daily_minimum'] ?? null;

                if ($floor === null) {
                    return false;
                }

                // The same derivation PayrollCalculator uses, so the two
                // cannot disagree about what a monthly salary is per day.
                return ((float) $employee->basic_salary * 12 / $factor) < (float) $floor;
            });

        if ($underpaid->isEmpty()) {
            return null;
        }

        return $this->entry(
            key: 'below_regional_minimum',
            severity: self::SEVERITY_WARNING,
            title: $underpaid->count().' below their regional wage floor',
            detail: 'Their daily rate falls under the minimum wage for the region they work in. '
                .'Check the current wage order — the rates in config/payroll.php go stale with every new one.',
            actionLabel: 'Review salaries',
            actionHref: '/hr/payroll/salaries',
            employees: $this->names($underpaid->map(fn (Employee $employee) => $employee->full_name)),
        );
    }

    /**
     * Unpaid suspensions covering days of this cutoff that the DTR does not
     * account for.
     *
     * **This check is the whole of how a Core 4 suspension reaches pay, and the
     * design decision it rests on is worth stating.** The shorter build was to
     * let Core 4 post a suspension and have this system mark those days absent.
     * That was rejected: a DTR another system can write is not a record of
     * anything — the same argument that keeps employees out of
     * `attendance_logs`, where they file a correction and somebody decides.
     * Core 4 is another system and is no more entitled to it than an employee.
     *
     * So the suspension is a stated fact and this is the report. HR keys the
     * days or decides not to, and either way a person decided.
     *
     * **A warning, never a blocker**, for the same reason the wage-floor check
     * is one: the discrepancy is often legitimate. A suspension served over a
     * rest day costs nothing; one that was lifted on appeal costs nothing; one
     * HR has already keyed as absent is *already handled*, and this check
     * would still see the suspension. Refusing to run payroll over any of
     * those would strand everybody else unpaid over a difference of opinion
     * about one person's Tuesday.
     *
     * The gap it leaves, stated: **an unpaid suspension nobody acts on is
     * paid.** That is the deliberate cost of not letting another system move
     * money in this one.
     *
     * @return array<string, mixed>|null
     */
    private function unservedSuspensions(PayrollPeriod $period): ?array
    {
        $from = $period->start_date;
        $to = $period->end_date;

        $actions = DisciplinaryAction::query()
            ->unpaidSuspensions()
            ->overlapping($from, $to)
            ->with('employee:id,first_name,middle_name,last_name,suffix')
            ->get();

        if ($actions->isEmpty()) {
            return null;
        }

        /*
         * What the DTR already accounts for, per employee: days marked absent
         * or on leave inside the cutoff.
         *
         * Counted once for everybody rather than per action — a per-employee
         * query here would be one round trip per suspension on a screen that
         * loads before every payroll run.
         */
        $accountedFor = AttendanceLog::query()
            ->whereIn('employee_id', $actions->pluck('employee_id')->unique())
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', [
                AttendanceLog::STATUS_ABSENT,
                AttendanceLog::STATUS_ON_LEAVE,
            ])
            ->selectRaw('employee_id, count(*) as days')
            ->groupBy('employee_id')
            ->pluck('days', 'employee_id');

        /*
         * Only the ones where the suspension is longer than what the DTR
         * explains. An employee whose four suspended days are already four
         * absences needs no attention, and listing them would put a line on
         * this panel that is already done — which is how a panel stops being
         * read.
         */
        $unaccounted = $actions->filter(function (DisciplinaryAction $action) use ($from, $to, $accountedFor) {
            return $action->daysWithin($from, $to)
                > (int) ($accountedFor[$action->employee_id] ?? 0);
        });

        if ($unaccounted->isEmpty()) {
            return null;
        }

        $days = $unaccounted->sum(fn (DisciplinaryAction $action) => $action->daysWithin($from, $to));

        return $this->entry(
            'unserved_suspensions',
            self::SEVERITY_WARNING,
            'Unpaid suspensions not reflected in the DTR',
            $unaccounted->count().' employee(s) are on unpaid suspension covering '.$days
                .' day(s) of this cutoff, and their attendance does not account for it. '
                .'This system does not dock pay on another system\'s say-so — key the days on '
                .'the DTR if the suspension was served, or leave it if it was lifted.',
            'Open Period DTR',
            '/hr/timekeeping/period',
            $this->names($unaccounted->map(fn (DisciplinaryAction $a) => $a->employee?->full_name)),
        );
    }

    private function names(Collection $names): array
    {
        return $names->filter()->unique()->sort()->take(5)->values()->all();
    }

    /** @return array<string, mixed> */
    private function entry(
        string $key,
        string $severity,
        string $title,
        string $detail,
        string $actionLabel,
        string $actionHref,
        array $employees,
    ): array {
        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'action_label' => $actionLabel,
            'action_href' => $actionHref,
            'employees' => $employees,
        ];
    }
}
