<?php

namespace App\Services;

use App\Models\AttendanceCutoff;
use App\Models\AttendanceLog;
use App\Models\Client;
use App\Models\ClientTimesheet;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\TimeCorrection;
use Illuminate\Support\Collection;

/**
 * What someone should look at before a payroll is computed.
 *
 * `PayrollService::gatherInputs()` reads attendance without judging it, so a
 * forgotten time-out quietly pays nothing for that day and a pending overtime
 * request quietly pays no overtime. This is where those are said out loud,
 * before the money is computed: incomplete punches, undecided overtime and
 * corrections, nobody's DTR at all, an open cutoff, a client that has not
 * confirmed its timesheet — plus the two that do not depend on attendance,
 * pay under the regional wage floor and unpaid suspensions.
 *
 * Severity is a workflow decision, not a data one:
 *
 *  - blocker — paying from this would be wrong.
 *  - warning — payable, but someone should have decided already.
 *
 * Nothing here hard-stops a run. HR may have a reason, and a payroll that
 * cannot be run is worse than one that warns loudly — but the decision is
 * recorded on screen instead of being invisible.
 */
class PayrollReadinessChecker
{
    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_WARNING = 'warning';

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
            $this->incompletePunches($period),
            $this->disputedTimesheets($period),
            $this->pendingCorrections($period),
            $this->pendingOvertime($period),
            $this->missingRecords($period),
            $this->unconfirmedTimesheets($period),
            $this->openCutoff($period),
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
     * Days clocked in with no time-out. The calculator computes nothing from
     * them, so the day pays as if it was never worked — a blocker, because the
     * payslip would be wrong in the employee's disfavour.
     *
     * @return array<string, mixed>|null
     */
    private function incompletePunches(PayrollPeriod $period): ?array
    {
        $logs = AttendanceLog::query()
            ->between($period->start_date->toDateString(), $period->end_date->toDateString())
            ->where('status', AttendanceLog::STATUS_INCOMPLETE)
            ->with('employee:id,first_name,middle_name,last_name,suffix')
            ->get();

        if ($logs->isEmpty()) {
            return null;
        }

        return $this->entry(
            'incomplete_punches',
            self::SEVERITY_BLOCKER,
            $logs->count().' day(s) with no time-out',
            'These days have a time-in and no time-out, so nothing was computed for them and they would pay as not worked. '
                .'Complete the record or approve the employee\'s correction first.',
            'Open time records',
            '/hr/timekeeping?status=incomplete&from='.$period->start_date->toDateString().'&to='.$period->end_date->toDateString(),
            $this->names($logs->map(fn (AttendanceLog $log) => $log->employee?->full_name)),
        );
    }

    /**
     * A client disputing its timesheet does not agree the work was done as
     * recorded — which is what both this payroll and their bill rest on.
     *
     * @return array<string, mixed>|null
     */
    private function disputedTimesheets(PayrollPeriod $period): ?array
    {
        $disputed = ClientTimesheet::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', ClientTimesheet::STATUS_DISPUTED)
            ->with('client:id,name')
            ->get();

        if ($disputed->isEmpty()) {
            return null;
        }

        return $this->entry(
            'disputed_timesheets',
            self::SEVERITY_BLOCKER,
            $disputed->count().' client timesheet(s) disputed',
            'The client did not confirm the attendance of the staff deployed to them. Correct the records and prepare the timesheet again before paying from it.',
            'Open client timesheets',
            '/hr/timekeeping/client-timesheets?period='.$period->id,
            $this->names($disputed->map(fn (ClientTimesheet $sheet) => $sheet->client?->name)),
        );
    }

    /** @return array<string, mixed>|null */
    private function pendingCorrections(PayrollPeriod $period): ?array
    {
        $pending = TimeCorrection::query()
            ->where('status', TimeCorrection::STATUS_PENDING)
            ->whereDate('work_date', '>=', $period->start_date->toDateString())
            ->whereDate('work_date', '<=', $period->end_date->toDateString())
            ->with('employee:id,first_name,middle_name,last_name,suffix')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        return $this->entry(
            'pending_corrections',
            self::SEVERITY_WARNING,
            $pending->count().' time correction(s) not yet decided',
            'An employee says these days were recorded wrong. Until someone decides, payroll pays each day as it is recorded now.',
            'Review corrections',
            '/hr/timekeeping/corrections?status=pending',
            $this->names($pending->map(fn (TimeCorrection $correction) => $correction->employee?->full_name)),
        );
    }

    /** @return array<string, mixed>|null */
    private function pendingOvertime(PayrollPeriod $period): ?array
    {
        $pending = OvertimeRequest::query()
            ->where('status', OvertimeRequest::STATUS_PENDING)
            ->whereDate('work_date', '>=', $period->start_date->toDateString())
            ->whereDate('work_date', '<=', $period->end_date->toDateString())
            ->with('employee:id,first_name,middle_name,last_name,suffix')
            ->get();

        if ($pending->isEmpty()) {
            return null;
        }

        return $this->entry(
            'pending_overtime',
            self::SEVERITY_WARNING,
            $pending->count().' overtime request(s) not yet decided',
            'Only approved overtime is paid, so these '.(float) $pending->sum('hours').' hour(s) would pay nothing if the run is computed now.',
            'Review overtime',
            '/hr/timekeeping/overtime?status=pending',
            $this->names($pending->map(fn (OvertimeRequest $request) => $request->employee?->full_name)),
        );
    }

    /**
     * Employees on this payroll with no time record at all in the cutoff.
     * Nothing says they were absent, so nothing is deducted — which may be
     * right, and should be checked.
     *
     * @return array<string, mixed>|null
     */
    private function missingRecords(PayrollPeriod $period): ?array
    {
        if ($period->start_date->isFuture()) {
            return null;
        }

        $recorded = AttendanceLog::query()
            ->between($period->start_date->toDateString(), $period->end_date->toDateString())
            ->distinct()
            ->pluck('employee_id');

        $missing = Employee::query()
            ->where('status', '!=', 'inactive')
            ->where('basic_salary', '>', 0)
            ->whereNotIn('id', $recorded)
            ->get();

        if ($missing->isEmpty()) {
            return null;
        }

        return $this->entry(
            'missing_records',
            self::SEVERITY_WARNING,
            $missing->count().' employee(s) with no time records',
            'Nobody recorded a single day for them in this cutoff, so they would be paid in full with no lateness or absence deducted.',
            'Open time records',
            '/hr/timekeeping?from='.$period->start_date->toDateString().'&to='.$period->end_date->toDateString(),
            $this->names($missing->map(fn (Employee $employee) => $employee->full_name)),
        );
    }

    /**
     * Clients with staff deployed to them whose timesheet for this period has
     * not been confirmed. Their attendance is still only our word.
     *
     * @return array<string, mixed>|null
     */
    private function unconfirmedTimesheets(PayrollPeriod $period): ?array
    {
        $answered = ClientTimesheet::query()
            ->where('payroll_period_id', $period->id)
            ->whereIn('status', [ClientTimesheet::STATUS_CONFIRMED, ClientTimesheet::STATUS_DISPUTED])
            ->pluck('client_id');

        $deployedTo = Employee::query()
            ->where('employment_category', 'external')
            ->where('status', '!=', 'inactive')
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id');

        $clients = Client::query()
            ->whereIn('id', $deployedTo)
            ->whereNotIn('id', $answered)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($clients->isEmpty()) {
            return null;
        }

        return $this->entry(
            'unconfirmed_timesheets',
            self::SEVERITY_WARNING,
            $clients->count().' client timesheet(s) not confirmed',
            'These clients have not confirmed the attendance of the staff deployed to them for this period.',
            'Open client timesheets',
            '/hr/timekeeping/client-timesheets?period='.$period->id,
            $this->names($clients->pluck('name')),
        );
    }

    /**
     * The cutoff is still open, so records inside it can change after the run
     * is computed — and the run would not know.
     *
     * @return array<string, mixed>|null
     */
    private function openCutoff(PayrollPeriod $period): ?array
    {
        $closed = AttendanceCutoff::query()
            ->where('payroll_period_id', $period->id)
            ->where('status', AttendanceCutoff::STATUS_CLOSED)
            ->exists();

        if ($closed) {
            return null;
        }

        return $this->entry(
            'cutoff_open',
            self::SEVERITY_WARNING,
            'Attendance cutoff is still open',
            'Time records for this period can still change after payroll is computed. Close the cutoff once the records are checked.',
            'Open cutoffs',
            '/hr/timekeeping/cutoffs',
            [],
        );
    }

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
     * Unpaid suspensions covering days of this cutoff.
     *
     * **This check is the whole of how a Core 4 suspension reaches pay.** Core 4
     * posts a suspension; this system does not dock pay on another system's
     * say-so. The suspension is a stated fact and this is the report — HR
     * records the days or decides not to, and either way a person decided.
     * It goes silent for a suspension once the DTR already explains its
     * days — every suspended day recorded absent, on leave, a rest day or a
     * holiday — since a line that is already done is how a panel stops being
     * read.
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

        // Days the DTR already shows the person did not work, one query for all.
        $explained = AttendanceLog::query()
            ->whereIn('employee_id', $actions->pluck('employee_id')->unique())
            ->between($from->toDateString(), $to->toDateString())
            ->whereIn('status', [AttendanceLog::STATUS_ABSENT, AttendanceLog::STATUS_ON_LEAVE, AttendanceLog::STATUS_REST_DAY, AttendanceLog::STATUS_HOLIDAY])
            ->get(['employee_id', 'work_date'])
            ->map(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->work_date->toDateString())
            ->flip();

        $unaccounted = $actions->filter(function (DisciplinaryAction $action) use ($from, $to, $explained) {
            $start = $action->effective_from->greaterThan($from) ? $action->effective_from->copy() : $from->copy();
            $end = $action->effective_to === null || $action->effective_to->greaterThan($to) ? $to->copy() : $action->effective_to->copy();

            for ($date = $start; $date->lessThanOrEqualTo($end); $date->addDay()) {
                if (! $explained->has($action->employee_id.'|'.$date->toDateString())) {
                    return true;
                }
            }

            return false;
        });

        if ($unaccounted->isEmpty()) {
            return null;
        }

        $days = $unaccounted->sum(fn (DisciplinaryAction $action) => $action->daysWithin($from, $to));

        return $this->entry(
            'unserved_suspensions',
            self::SEVERITY_WARNING,
            'Unpaid suspensions in this cutoff',
            $unaccounted->count().' employee(s) are on unpaid suspension covering '.$days
                .' day(s) of this cutoff. '
                .'This system does not dock pay on another system\'s say-so — record the days as '
                .'unpaid leave or a deduction if the suspension was served, or leave it if it was lifted.',
            'Open employees',
            '/hr/employees',
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
