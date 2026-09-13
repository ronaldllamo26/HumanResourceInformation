<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Flags DTR records worth a second look before payroll pays them.
 *
 * Config-driven, same pattern as PayrollCalculator and PerformanceScorer:
 * thresholds live in config/timekeeping.php, so tightening a rule is a config
 * edit. Two kinds of exception:
 *
 *  - record-level: a single day crosses a threshold (no time-out, very late,
 *    suspicious overtime)
 *  - pattern-level: an employee crosses a threshold across the filtered range
 *    (frequent lateness, frequent absence) — no single day looks wrong, the
 *    trend does
 */
class AttendanceExceptionScanner
{
    public const TYPE_MISSING_PUNCH = 'missing_punch';

    public const TYPE_EXCESSIVE_LATE = 'excessive_late';

    public const TYPE_EXCESSIVE_OVERTIME = 'excessive_overtime';

    public const TYPE_FREQUENT_LATE = 'frequent_late';

    public const TYPE_FREQUENT_ABSENCE = 'frequent_absence';

    /** Absent, with no approved leave behind it. */
    public const TYPE_AWOL = 'awol';

    /** Absent on a day an approved leave covers — the DTR row is stale. */
    public const TYPE_UNRECORDED_LEAVE = 'unrecorded_leave';

    /**
     * LeaveService is what turns an absence into either an excused day or an
     * AWOL. Without it the two modules each hold half the answer: attendance
     * knows somebody did not come in, leave knows they were allowed not to,
     * and nobody put the two together.
     */
    public function __construct(private readonly LeaveService $leave) {}

    /**
     * @return Collection<int, array<string, mixed>> newest first, employee
     *                                               name second — matches how the screen groups them
     */
    public function scan(Builder $query): Collection
    {
        $logs = (clone $query)->reorder()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix')
            ->get();

        // Sorting on the word "critical"/"warning" would work alphabetically by
        // accident; a weight makes the ordering explicit instead of coincidental.
        $severityWeight = ['critical' => 0, 'warning' => 1];

        return $this->recordLevel($logs)
            ->concat($this->leaveLevel($logs))
            ->concat($this->patternLevel($logs))
            ->sortBy([
                fn ($entry) => $severityWeight[$entry['severity']] ?? 2,
                fn ($entry) => $entry['employee_name'],
            ])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recordLevel(Collection $logs): Collection
    {
        $lateThreshold = (int) config('timekeeping.late_minutes_threshold');
        $overtimeThreshold = (int) config('timekeeping.overtime_hours_threshold') * 60;
        $staleDays = (int) config('timekeeping.stale_open_punch_days');
        $today = Carbon::today();

        return $logs->flatMap(function (AttendanceLog $log) use ($lateThreshold, $overtimeThreshold, $staleDays, $today) {
            $exceptions = [];

            if ($log->time_in && ! $log->time_out && $log->log_date->lt($today->copy()->subDays($staleDays))) {
                $exceptions[] = $this->entry($log, self::TYPE_MISSING_PUNCH, 'critical',
                    "Timed in but never timed out on {$log->log_date->toFormattedDateString()}.",
                );
            }

            if ($log->late_minutes > $lateThreshold) {
                $exceptions[] = $this->entry($log, self::TYPE_EXCESSIVE_LATE, 'warning',
                    "{$log->late_minutes} minutes late on {$log->log_date->toFormattedDateString()}.",
                );
            }

            if ($log->overtime_minutes > $overtimeThreshold) {
                $hours = round($log->overtime_minutes / 60, 1);
                $exceptions[] = $this->entry($log, self::TYPE_EXCESSIVE_OVERTIME, 'warning',
                    "{$hours}h overtime on {$log->log_date->toFormattedDateString()} — worth confirming before it is approved.",
                );
            }

            return $exceptions;
        });
    }

    /**
     * The Module 2 → Module 3 cross-check: every absence, asked of leave.
     *
     * Two findings come out of it, and they point in opposite directions:
     *
     *  - **AWOL** — absent with nothing filed. Critical, because it is a
     *    disciplinary matter and an unpaid day, and because nobody chasing it
     *    on the day will remember it at cutoff.
     *  - **Unrecorded leave** — absent on a day an approved leave *does*
     *    cover, which means the DTR row is stale (leave approved after the day
     *    was keyed). A warning rather than an error: payroll already ignores
     *    it, so the money is right and only the record reads wrong.
     *
     * The range is taken from the logs themselves rather than passed in, so
     * every existing caller keeps working — PayrollReadinessChecker and the
     * Exceptions screen both hand this a query and nothing else.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function leaveLevel(Collection $logs): Collection
    {
        $absences = $logs->where('status', AttendanceLog::STATUS_ABSENT);

        if ($absences->isEmpty()) {
            return collect();
        }

        $dates = $absences->pluck('log_date');

        $covered = $this->leave->approvedLeaveDates(
            $absences->pluck('employee_id')->filter()->unique()->values()->all(),
            Carbon::parse($dates->min()),
            Carbon::parse($dates->max()),
        );

        return $absences->map(function (AttendanceLog $log) use ($covered) {
            $leave = $covered->get($log->employee_id.'|'.$log->log_date->toDateString());
            $day = $log->log_date->toFormattedDateString();

            if ($leave === null) {
                return $this->entry($log, self::TYPE_AWOL, 'critical',
                    "Absent on {$day} with no approved leave on file.",
                );
            }

            return $this->entry($log, self::TYPE_UNRECORDED_LEAVE, 'warning',
                "Marked absent on {$day}, but {$leave['leave_type']} was approved for it — the record should say on leave.",
            );
        })->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function patternLevel(Collection $logs): Collection
    {
        $lateCountThreshold = (int) config('timekeeping.frequent_late_count');
        $absenceCountThreshold = (int) config('timekeeping.frequent_absence_count');

        $byEmployee = $logs->groupBy('employee_id')->filter(fn ($rows, $id) => $id !== null);

        $late = $byEmployee
            ->map(fn (Collection $rows) => $rows->where('late_minutes', '>', 0)->count())
            ->filter(fn (int $count) => $count >= $lateCountThreshold);

        $absent = $byEmployee
            ->map(fn (Collection $rows) => $rows->where('status', AttendanceLog::STATUS_ABSENT)->count())
            ->filter(fn (int $count) => $count >= $absenceCountThreshold);

        $patterns = collect();

        foreach ($late as $employeeId => $count) {
            $employee = $byEmployee[$employeeId]->first()->employee;
            $patterns->push($this->patternEntry(
                $employee, self::TYPE_FREQUENT_LATE, 'warning',
                "Late {$count} time(s) in this range.", $count,
            ));
        }

        foreach ($absent as $employeeId => $count) {
            $employee = $byEmployee[$employeeId]->first()->employee;
            $patterns->push($this->patternEntry(
                $employee, self::TYPE_FREQUENT_ABSENCE, 'critical',
                "Absent {$count} time(s) in this range.", $count,
            ));
        }

        return $patterns;
    }

    /** @return array<string, mixed> */
    private function entry(AttendanceLog $log, string $type, string $severity, string $detail): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'employee_id' => $log->employee_id,
            'employee_number' => $log->employee?->employee_number,
            'employee_name' => $log->employee?->full_name ?? '—',
            'log_date' => $log->log_date->toDateString(),
            'detail' => $detail,
            'attendance_log_id' => $log->id,
        ];
    }

    /** @return array<string, mixed> */
    private function patternEntry(?Employee $employee, string $type, string $severity, string $detail, int $count): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'employee_id' => $employee?->id,
            'employee_number' => $employee?->employee_number,
            'employee_name' => $employee?->full_name ?? '—',
            'log_date' => null,
            'detail' => $detail,
            'attendance_log_id' => null,
        ];
    }
}
