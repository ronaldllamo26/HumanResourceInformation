<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Module 2 — Timekeeping & Attendance.
 *
 * Owns persistence and scoping; the arithmetic lives in AttendanceCalculator.
 */
class TimekeepingService
{
    /**
     * What "came in" means, stated once.
     *
     * Three statuses rather than one, because a day somebody was late for or
     * left early from is still a day they were there. Four places count it —
     * the tiles, the per-employee summary, the per-employee totals, and the
     * `attended` filter — and a copy of this list that fell out of step would
     * put two different figures for the same fortnight on one screen.
     */
    public const PRESENT_STATUSES = [
        AttendanceLog::STATUS_PRESENT,
        AttendanceLog::STATUS_LATE,
        AttendanceLog::STATUS_UNDERTIME,
    ];

    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly EmployeeService $employees,
    ) {}

    /** DTR rows the viewer is allowed to see, mirroring the employee directory. */
    public function scopedQuery(User $user): Builder
    {
        return AttendanceLog::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id', 'shift:id,name,start_time,end_time'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Creates or updates the single DTR row for an employee/date, recomputing
     * every derived figure from the punches.
     *
     * $context lets a caller filling a whole cutoff hand over the shift, the
     * rest day, and the holiday it has already looked up. Resolving those per
     * cell is four queries a day per employee — some 2,400 for one fortnightly
     * sheet — for three facts the sheet loaded once in three queries. Omit it
     * and the row resolves exactly as it always did, so there is still one
     * door into attendance_logs rather than a fast one and a correct one.
     *
     * @param  array{shift?: ?Shift, is_rest_day?: bool, is_holiday?: bool}|null  $context
     */
    public function record(Employee $employee, array $data, ?array $context = null): AttendanceLog
    {
        $date = Carbon::parse($data['log_date'])->startOfDay();

        $shift = match (true) {
            isset($data['shift_id']) => Shift::find($data['shift_id']),
            $context !== null && array_key_exists('shift', $context) => $context['shift'],
            default => $this->resolveShift($employee, $date),
        };

        $computed = $this->calculator->compute(
            date: $date,
            shift: $shift,
            timeIn: $this->punch($date, $data['time_in'] ?? null),
            timeOut: $this->punch($date, $data['time_out'] ?? null, $shift),
            breakOut: $this->punch($date, $data['break_out'] ?? null),
            breakIn: $this->punch($date, $data['break_in'] ?? null),
            isRestDay: $context['is_rest_day'] ?? $this->isRestDay($employee, $date),
            isHoliday: $context['is_holiday'] ?? $this->isHoliday($date),
        );

        // An explicitly chosen status (on_leave, absent) wins over the derived one.
        if (! empty($data['status'])) {
            $computed['status'] = $data['status'];
        }

        $attributes = [
            ...$computed,
            'shift_id' => $shift?->id,
            'time_in' => $this->punch($date, $data['time_in'] ?? null),
            'time_out' => $this->punch($date, $data['time_out'] ?? null, $shift),
            'break_out' => $this->punch($date, $data['break_out'] ?? null),
            'break_in' => $this->punch($date, $data['break_in'] ?? null),
            'source' => $data['source'] ?? 'manual',
            'remarks' => $data['remarks'] ?? null,
            'biometric_device_id' => $data['biometric_device_id'] ?? null,
        ];

        // updateOrCreate matches on exact column equality, which misses rows
        // whose date is stored with a time component. whereDate compares the
        // date part on every driver.
        $existing = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('log_date', $date)
            ->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return AttendanceLog::create([
            ...$attributes,
            'employee_id' => $employee->id,
            'log_date' => $date,
        ]);
    }

    /** The shift in force for this employee on this date, if any. */
    public function resolveShift(Employee $employee, Carbon $date): ?Shift
    {
        $schedules = EmployeeSchedule::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->get();

        return $this->scheduleFor($schedules, $date)?->shift;
    }

    /**
     * Picks the schedule governing a date out of an already-loaded set.
     *
     * The cutoff sheet loads every employee's schedules in one query and asks
     * this per day; resolveShift() loads one employee's and asks it once. The
     * rule for *which* schedule wins — in force on the day, latest first, and
     * covering that weekday — is written here alone, so a sheet and a single
     * record cannot come to disagree about whose Saturday is a rest day.
     *
     * @param  Collection<int, EmployeeSchedule>  $schedules
     */
    public function scheduleFor(Collection $schedules, Carbon $date): ?EmployeeSchedule
    {
        return $schedules
            ->filter(fn (EmployeeSchedule $candidate) => $candidate->effective_from->lte($date)
                && ($candidate->effective_to === null || $candidate->effective_to->gte($date)))
            ->sortByDesc('effective_from')
            ->first(fn (EmployeeSchedule $candidate) => $candidate->coversDate($date));
    }

    /** True when the employee has a schedule, but none covering this weekday. */
    public function isRestDay(Employee $employee, Carbon $date): bool
    {
        $schedules = EmployeeSchedule::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->effectiveOn($date)
            ->get();

        return $schedules->isNotEmpty()
            && $this->scheduleFor($schedules, $date)?->shift === null;
    }

    public function isHoliday(Carbon $date): bool
    {
        return Holiday::whereDate('date', $date)->exists();
    }

    /**
     * The cutoff DTR sheet: every employee in scope, and what is already
     * stored against each day of the period.
     *
     * Four queries whatever the sheet's size. Asking per cell instead — a
     * shift, a rest day, and a holiday for every employee-day — is some 2,400
     * queries for a fortnight of forty people, which is the difference
     * between a screen that opens and one that gives up.
     *
     * Somebody hired after the cutoff ends is not on the sheet, and neither is
     * an inactive record. An employee separated *inside* the cutoff is still
     * listed until release marks them inactive, which is the window their last
     * DTR is normally encoded in.
     *
     * @param  array<string, mixed>  $filters
     * @return array{days: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    public function periodSheet(User $user, Carbon $from, Carbon $to, array $filters = []): array
    {
        $employees = $this->employees->scopedQuery($user)
            ->filter($filters)
            ->where('status', '!=', 'inactive')
            ->whereDate('date_hired', '<=', $to->toDateString())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $ids = $employees->pluck('id')->all();
        $days = $this->periodDays($from, $to);

        $logs = $this->logsFor($ids, $from, $to);
        $schedules = $this->schedulesFor($ids, $from, $to);

        $rows = $employees->map(fn (Employee $employee) => [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'department' => $employee->department?->name,
            'client' => $employee->client?->name,
            'cells' => collect($days)->mapWithKeys(fn (array $day) => [
                $day['date'] => $this->cell(
                    $logs->get($employee->id.'|'.$day['date']),
                    $day,
                    $schedules->get($employee->id) ?? collect(),
                ),
            ])->all(),
        ])->values()->all();

        return ['days' => $days, 'rows' => $rows];
    }

    /**
     * Writes the cutoff sheet.
     *
     * Only cells that actually changed reach record(). AttendanceLog is
     * Auditable, so writing the whole grid unconditionally would leave 600
     * audit rows behind every time somebody opened the screen and pressed
     * Save — and the History screen, which exists to show who corrected a DTR,
     * would be unreadable by the second cutoff.
     *
     * @param  array<int, array{employee_id: int|string, log_date: string, status: string|null}>  $cells
     * @return array{saved: int, cleared: int, locked: int, skipped: int}
     */
    public function saveSheet(User $user, Carbon $from, Carbon $to, array $cells): array
    {
        // Re-derived here rather than trusted from the form. The browser sends
        // ids; which employees this user may write time for is not the
        // browser's answer to give.
        $employees = $this->employees->scopedQuery($user)
            ->whereIn('employees.id', collect($cells)->pluck('employee_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $ids = $employees->keys()->all();

        $logs = $this->logsFor($ids, $from, $to);
        $schedules = $this->schedulesFor($ids, $from, $to);
        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Holiday $holiday) => $holiday->date->toDateString())
            ->flip();

        $result = ['saved' => 0, 'cleared' => 0, 'locked' => 0, 'skipped' => 0];

        DB::transaction(function () use ($cells, $employees, $logs, $schedules, $holidays, $from, $to, &$result) {
            foreach ($cells as $cell) {
                $employee = $employees->get((int) $cell['employee_id']);
                $date = Carbon::parse($cell['log_date'])->startOfDay();

                // A day outside the cutoff on screen is not this screen's to
                // write, whatever the payload says.
                if ($employee === null || $date->lt($from) || $date->gt($to)) {
                    $result['skipped']++;

                    continue;
                }

                $status = $cell['status'] ?: null;
                $existing = $logs->get($employee->id.'|'.$date->toDateString());

                // A row carrying real punches was written by the biometric
                // import or by hand on Daily Records. A status dropdown able
                // to overwrite it would destroy the times silently, which is
                // the one thing a DTR may not do.
                if ($existing !== null && ($existing->time_in !== null || $existing->time_out !== null)) {
                    $result['locked']++;

                    continue;
                }

                if ($status === null) {
                    if ($existing !== null) {
                        $existing->delete();
                        $result['cleared']++;
                    } else {
                        $result['skipped']++;
                    }

                    continue;
                }

                if ($existing !== null && $existing->status === $status) {
                    $result['skipped']++;

                    continue;
                }

                $employeeSchedules = $schedules->get($employee->id) ?? collect();
                $schedule = $this->scheduleFor($employeeSchedules, $date);

                $this->record($employee, [
                    'log_date' => $date->toDateString(),
                    'status' => $status,
                    'source' => 'manual',
                    // record() rewrites the whole row, so a remark typed on
                    // Daily Records has to be carried across or correcting a
                    // status here would quietly erase it.
                    'remarks' => $existing?->remarks,
                ], [
                    'shift' => $schedule?->shift,
                    'is_rest_day' => $employeeSchedules->isNotEmpty() && $schedule?->shift === null,
                    'is_holiday' => $holidays->has($date->toDateString()),
                ]);

                $result['saved']++;
            }
        });

        return $result;
    }

    /**
     * Days worked per employee over a range — what the Records screen opens on.
     *
     * Paginates *employees* rather than attendance rows, which is the whole
     * difference from employeeSummaries(): that one groups the logs, so
     * somebody with nothing recorded simply is not in the result. On a screen
     * answering "who came in this cutoff", a person with no attendance at all
     * is the answer rather than a row to leave out.
     *
     * Two queries a page — the employees, then one aggregate over their logs.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function attendanceByEmployee(User $user, Carbon $from, Carbon $to, int $perPage = 25): LengthAwarePaginator
    {
        $employees = $this->employees->scopedQuery($user)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage)
            ->withQueryString();

        $totals = $this->totalsByEmployee($employees->pluck('id')->all(), $from, $to);

        return $employees->through(function (Employee $employee) use ($totals) {
            $row = $totals->get($employee->id);

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'client' => $employee->client?->name,
                'days_present' => (int) ($row?->days_present ?? 0),
                'days_absent' => (int) ($row?->days_absent ?? 0),
                'days_recorded' => (int) ($row?->days_recorded ?? 0),
                'late_count' => (int) ($row?->late_count ?? 0),
                'late_minutes' => (int) ($row?->late_minutes ?? 0),
                'undertime_minutes' => (int) ($row?->undertime_minutes ?? 0),
                'overtime_hours' => round(((int) ($row?->overtime_minutes ?? 0)) / 60, 2),
                'total_hours' => round((float) ($row?->hours_worked ?? 0), 2),
            ];
        });
    }

    /**
     * One employee's days inside the range — the rows behind an expanded
     * summary line.
     *
     * Read through scopedQuery() rather than by id alone, so expanding
     * somebody a supervisor may not see returns nothing instead of their DTR.
     *
     * @return Collection<int, AttendanceLog>
     */
    public function daysFor(User $user, int $employeeId, Carbon $from, Carbon $to): Collection
    {
        return $this->scopedQuery($user)
            ->where('employee_id', $employeeId)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('log_date')
            ->get();
    }

    /**
     * One employee's range laid out Monday to Sunday.
     *
     * A fortnight as a list of dates makes the reader count weekends out of it
     * themselves; as a calendar the pattern is the thing you see first — three
     * Mondays missed reads instantly and does not read at all down a column of
     * fourteen rows.
     *
     * Weeks run from the Monday on or before the range to the Sunday on or
     * after it, so every row has seven cells. Days outside the cutoff are
     * marked rather than dropped: a week missing its first two days stops
     * being a week, and the gap is what tells the reader the cutoff starts
     * mid-week.
     *
     * @param  Collection<int, AttendanceLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    public function attendanceCalendar(Collection $logs, Carbon $from, Carbon $to): array
    {
        $byDate = $logs->keyBy(fn (AttendanceLog $log) => $log->log_date->toDateString());

        $holidays = Holiday::query()
            ->whereBetween('date', [
                $from->copy()->startOfWeek()->toDateString(),
                $to->copy()->endOfWeek()->toDateString(),
            ])
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $weeks = [];
        $cursor = $from->copy()->startOfWeek();
        $last = $to->copy()->endOfWeek();

        while ($cursor->lte($last)) {
            $days = [];

            for ($offset = 0; $offset < 7; $offset++) {
                $date = $cursor->copy()->addDays($offset);
                $key = $date->toDateString();
                $log = $byDate->get($key);

                $days[] = [
                    'date' => $key,
                    'day' => $date->day,
                    'in_range' => $date->betweenIncluded($from, $to),
                    'holiday' => $holidays->get($key)?->name,
                    'status' => $log?->status,
                    'time_in' => $log?->time_in?->format('H:i'),
                    'time_out' => $log?->time_out?->format('H:i'),
                    'hours_worked' => $log === null ? null : round((float) $log->hours_worked, 2),
                    'late_minutes' => (int) ($log?->late_minutes ?? 0),
                    'overtime_minutes' => (int) ($log?->overtime_minutes ?? 0),
                ];
            }

            /*
             * Hours per week, which no other screen answers.
             *
             * Daily totals are on the row and range totals are on the tiles,
             * and neither says whether somebody worked a 60-hour week — which
             * is the figure a rest-day rule and an overtime pattern are both
             * read against. Summed over the days *inside the cutoff only*, so
             * a week straddling the boundary reports the part this period is
             * paying for rather than a figure the payslip will not match.
             */
            $inRange = collect($days)->where('in_range', true);

            $weeks[] = [
                'starts_on' => $cursor->toDateString(),
                'days' => $days,
                'hours_worked' => round((float) $inRange->sum('hours_worked'), 2),
                'days_present' => $inRange->whereIn('status', self::PRESENT_STATUSES)->count(),
                'overtime_minutes' => (int) $inRange->sum('overtime_minutes'),
            ];

            $cursor->addWeek();
        }

        return $weeks;
    }

    /** Headline figures for the filtered DTR range. */
    public function summary(Builder $query): array
    {
        $rows = (clone $query)->reorder()->get([
            'status', 'late_minutes', 'undertime_minutes', 'overtime_minutes', 'hours_worked',
        ]);

        return [
            'records' => $rows->count(),
            'present' => $rows->whereIn('status', self::PRESENT_STATUSES)->count(),
            'absent' => $rows->where('status', AttendanceLog::STATUS_ABSENT)->count(),
            'late' => $rows->where('late_minutes', '>', 0)->count(),
            'total_hours' => round((float) $rows->sum('hours_worked'), 2),
            'overtime_hours' => round($rows->sum('overtime_minutes') / 60, 2),
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'undertime_minutes' => (int) $rows->sum('undertime_minutes'),
        ];
    }

    /**
     * Per-employee totals for the filtered range — the monthly attendance
     * report, and the shape Payroll will read a period from.
     *
     * Aggregated in SQL so a full year stays a single query.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function employeeSummaries(Builder $query): Collection
    {
        $presentList = "'".implode("','", self::PRESENT_STATUSES)."'";

        return (clone $query)
            ->reorder()
            ->with(['employee.department:id,name'])
            ->selectRaw('employee_id')
            ->selectRaw("sum(case when status in ({$presentList}) then 1 else 0 end) as days_present")
            ->selectRaw("sum(case when status = '".AttendanceLog::STATUS_ABSENT."' then 1 else 0 end) as days_absent")
            ->selectRaw('sum(case when late_minutes > 0 then 1 else 0 end) as late_count')
            ->selectRaw('sum(late_minutes) as total_late_minutes')
            ->selectRaw('sum(undertime_minutes) as total_undertime_minutes')
            ->selectRaw('sum(overtime_minutes) as total_overtime_minutes')
            ->selectRaw('sum(night_diff_minutes) as total_night_diff_minutes')
            ->selectRaw('sum(hours_worked) as total_hours_worked')
            ->groupBy('employee_id')
            ->get()
            ->map(fn (AttendanceLog $row) => [
                'employee_id' => $row->employee_id,
                'employee_number' => $row->employee?->employee_number,
                'full_name' => $row->employee?->full_name ?? '—',
                'department' => $row->employee?->department?->name,
                'days_present' => (int) $row->days_present,
                'days_absent' => (int) $row->days_absent,
                'late_count' => (int) $row->late_count,
                'late_minutes' => (int) $row->total_late_minutes,
                'undertime_minutes' => (int) $row->total_undertime_minutes,
                'overtime_hours' => round($row->total_overtime_minutes / 60, 2),
                'night_diff_hours' => round($row->total_night_diff_minutes / 60, 2),
                'total_hours' => round((float) $row->total_hours_worked, 2),
            ])
            ->sortBy('full_name')
            ->values();
    }

    /**
     * One square of the sheet: what is stored, or what the calendar suggests
     * where nothing is.
     *
     * A suggestion is drawn and saved only if it is submitted — the same
     * bargain the document scanner makes with a filled form.
     *
     * @param  array<string, mixed>  $day
     * @param  Collection<int, EmployeeSchedule>  $schedules
     * @return array<string, mixed>
     */
    private function cell(?AttendanceLog $log, array $day, Collection $schedules): array
    {
        if ($log !== null) {
            return [
                'status' => $log->status,
                'locked' => $log->time_in !== null || $log->time_out !== null,
                'time_in' => $log->time_in?->format('H:i'),
                'time_out' => $log->time_out?->format('H:i'),
                'source' => $log->source,
                'suggested' => null,
            ];
        }

        $date = Carbon::parse($day['date']);

        return [
            'status' => null,
            'locked' => false,
            'time_in' => null,
            'time_out' => null,
            'source' => null,
            'suggested' => match (true) {
                $day['holiday'] !== null => AttendanceLog::STATUS_HOLIDAY,
                $schedules->isNotEmpty()
                    && $this->scheduleFor($schedules, $date)?->shift === null => AttendanceLog::STATUS_REST_DAY,
                default => null,
            },
        ];
    }

    /**
     * The days of the cutoff, each carrying the holiday it falls on. Holidays
     * are read from the same table AttendanceCalculator and LeaveService use,
     * so the sheet cannot suggest a working day the payroll premium disagrees
     * with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function periodDays(Carbon $from, Carbon $to): array
    {
        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->date->toDateString());

        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();

            $days[] = [
                'date' => $key,
                'day' => $date->day,
                'weekday' => $date->format('D'),
                'is_weekend' => $date->isWeekend(),
                'holiday' => $holidays->get($key)?->name,
            ];
        }

        return $days;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<string, AttendanceLog>
     */
    private function logsFor(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        return AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->log_date->toDateString());
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, EmployeeSchedule>>
     */
    private function schedulesFor(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        return EmployeeSchedule::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(function (Builder $inner) use ($from) {
                $inner->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from->toDateString());
            })
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, AttendanceLog>
     */
    private function totalsByEmployee(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        $presentList = "'".implode("','", self::PRESENT_STATUSES)."'";

        return AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('employee_id')
            ->selectRaw('count(*) as days_recorded')
            ->selectRaw("sum(case when status in ({$presentList}) then 1 else 0 end) as days_present")
            ->selectRaw("sum(case when status = '".AttendanceLog::STATUS_ABSENT."' then 1 else 0 end) as days_absent")
            ->selectRaw('sum(case when late_minutes > 0 then 1 else 0 end) as late_count')
            ->selectRaw('sum(late_minutes) as late_minutes')
            ->selectRaw('sum(undertime_minutes) as undertime_minutes')
            ->selectRaw('sum(overtime_minutes) as overtime_minutes')
            ->selectRaw('sum(hours_worked) as hours_worked')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Combines a date with a "HH:MM" time. A time-out that lands before the
     * time-in belongs to the following day (night shifts).
     */
    private function punch(Carbon $date, ?string $time, ?Shift $shift = null): ?Carbon
    {
        if (blank($time)) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');
        $punch = $date->copy()->setTime((int) $hour, (int) $minute, 0);

        if ($shift?->crossesMidnight() && (int) $hour < (int) explode(':', $shift->start_time)[0]) {
            $punch->addDay();
        }

        return $punch;
    }
}
