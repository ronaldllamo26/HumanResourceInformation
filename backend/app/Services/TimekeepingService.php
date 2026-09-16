<?php

namespace App\Services;

use App\Models\AttendanceCutoff;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\TimeCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily time records, overtime and corrections — everything that writes a day.
 *
 * `record()` is the only way into `attendance_logs`: HR's entry, an imported
 * biometric file and an approved correction all go through it, so every day is
 * computed by the same calculator and refused the same way once its cutoff is
 * closed.
 */
class TimekeepingService
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly WorkCalendar $calendar,
        private readonly EmployeeService $employees,
        private readonly LeaveService $leave,
    ) {}

    /** Time records the user may see: everyone for HR, the team for a supervisor, their own otherwise. */
    public function scopedLogs(User $user): Builder
    {
        return AttendanceLog::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id,client_id', 'shift:id,code,name'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Employees the user may pick on a Time & Attendance form, as options.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function employeeOptions(User $user): array
    {
        return $this->employees->scopedQuery($user)
            ->where('status', '!=', 'inactive')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => "{$employee->full_name} ({$employee->employee_number})",
            ])
            ->all();
    }

    /**
     * Every closed period's dates, loaded once so a list screen can mark its
     * locked rows without a query per row.
     *
     * @return Collection<int, array{0: string, 1: string}>
     */
    public function lockedRanges(): Collection
    {
        return AttendanceCutoff::query()
            ->where('status', AttendanceCutoff::STATUS_CLOSED)
            ->with('period:id,start_date,end_date')
            ->get()
            ->filter(fn (AttendanceCutoff $cutoff) => $cutoff->period)
            ->map(fn (AttendanceCutoff $cutoff) => [
                $cutoff->period->start_date->toDateString(),
                $cutoff->period->end_date->toDateString(),
            ])
            ->values();
    }

    public function isLockedIn(Collection $ranges, Carbon|string $date): bool
    {
        $day = $date instanceof Carbon ? $date->toDateString() : substr($date, 0, 10);

        return $ranges->contains(fn (array $range) => $day >= $range[0] && $day <= $range[1]);
    }

    /** The closed cutoff covering a date, if there is one. */
    public function closedCutoffOn(Carbon $date): ?AttendanceCutoff
    {
        return AttendanceCutoff::query()
            ->where('status', AttendanceCutoff::STATUS_CLOSED)
            ->whereHas('period', fn (Builder $period) => $period
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString()))
            ->with('period:id,name')
            ->first();
    }

    /** Refuses any change to a day inside a closed cutoff. */
    public function assertOpen(Carbon $date, string $field = 'work_date'): void
    {
        $cutoff = $this->closedCutoffOn($date);

        if ($cutoff) {
            throw ValidationException::withMessages([
                $field => "The cutoff for {$cutoff->period?->name} is closed, so {$date->format('M j, Y')} can no longer be changed.",
            ]);
        }
    }

    /**
     * Writes one employee's day, computing it from the punches and the shift.
     *
     * `$timeIn` / `$timeOut` are "HH:MM" on the work date; a time-out earlier
     * than the time-in belongs to the next morning (a night shift).
     */
    public function record(
        Employee $employee,
        Carbon $date,
        ?string $timeIn,
        ?string $timeOut,
        string $source = AttendanceLog::SOURCE_MANUAL,
        ?string $remarks = null,
        ?User $by = null,
    ): AttendanceLog {
        $date = $date->copy()->startOfDay();
        $this->assertOpen($date);

        $in = $this->at($date, $timeIn);
        $out = $this->at($date, $timeOut);

        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        $schedule = $this->calendar->scheduleFor($employee, $date);
        $holiday = $this->calendar->holidayOn($date);
        $restDay = $this->calendar->isRestDay($employee, $date);

        $figures = $this->calculator->compute($date, $schedule?->shift, $in, $out, $restDay, (bool) $holiday);

        // An absence covered by approved leave is leave, not an absence.
        if ($figures['status'] === AttendanceLog::STATUS_ABSENT
            && $this->leave->approvedLeaveDates([$employee->id], $date, $date)->isNotEmpty()) {
            $figures['status'] = AttendanceLog::STATUS_ON_LEAVE;
        }

        $log = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->first() ?? new AttendanceLog(['employee_id' => $employee->id, 'work_date' => $date->toDateString()]);

        $log->fill([
            ...$figures,
            'shift_id' => $schedule?->shift_id,
            'time_in' => $in,
            'time_out' => $out,
            'source' => $source,
            'remarks' => $remarks,
            'recorded_by' => $by?->id,
        ])->save();

        return $log;
    }

    public function delete(AttendanceLog $log): void
    {
        $this->assertOpen($log->work_date);
        $log->delete();
    }

    /**
     * Reads a biometric export: employee_number, date, time_in, time_out.
     *
     * Each row goes through `record()`, so a row in a closed cutoff or for an
     * unknown employee is refused on its own and the rest still land.
     *
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function import(UploadedFile $file, User $by): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), fgetcsv($handle) ?: []);
        $required = ['employee_number', 'date', 'time_in', 'time_out'];

        if (array_diff($required, $header) !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'The file needs these columns: '.implode(', ', $required).'.',
            ]);
        }

        $imported = 0;
        $skipped = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $data = array_combine($header, array_pad($row, count($header), null));
            $employee = Employee::where('employee_number', trim((string) $data['employee_number']))->first();

            if (! $employee) {
                $skipped[$line] = "Row {$line}: no employee numbered \"{$data['employee_number']}\".";

                continue;
            }

            try {
                $date = Carbon::parse(trim((string) $data['date']));
                $this->record(
                    $employee,
                    $date,
                    $this->timeOrNull($data['time_in']),
                    $this->timeOrNull($data['time_out']),
                    AttendanceLog::SOURCE_IMPORT,
                    null,
                    $by,
                );
                $imported++;
            } catch (ValidationException $exception) {
                $skipped[$line] = "Row {$line}: ".collect($exception->errors())->flatten()->first();
            } catch (\Throwable) {
                $skipped[$line] = "Row {$line}: the date or time could not be read.";
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => array_values($skipped)];
    }

    /**
     * Approves or rejects an overtime request. Only approved hours are paid.
     */
    public function decideOvertime(OvertimeRequest $request, User $by, bool $approve, ?string $remarks = null): OvertimeRequest
    {
        $this->assertOpen($request->work_date, 'status');

        $request->update([
            'status' => $approve ? OvertimeRequest::STATUS_APPROVED : OvertimeRequest::STATUS_REJECTED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_remarks' => $remarks,
        ]);

        return $request->refresh();
    }

    /**
     * Approves or rejects a correction; approving rewrites the day through
     * `record()`, in one transaction, so an approval can never be saved with a
     * record that did not change.
     */
    public function decideCorrection(TimeCorrection $correction, User $by, bool $approve, ?string $remarks = null): TimeCorrection
    {
        $this->assertOpen($correction->work_date, 'status');

        return DB::transaction(function () use ($correction, $by, $approve, $remarks) {
            if ($approve) {
                $existing = AttendanceLog::where('employee_id', $correction->employee_id)
                    ->whereDate('work_date', $correction->work_date->toDateString())
                    ->first();

                // A blank punch on the request leaves the existing punch alone.
                $this->record(
                    $correction->employee,
                    $correction->work_date,
                    $correction->time_in?->format('H:i') ?? $existing?->time_in?->format('H:i'),
                    $correction->time_out?->format('H:i') ?? $existing?->time_out?->format('H:i'),
                    AttendanceLog::SOURCE_CORRECTION,
                    'Correction: '.$correction->reason,
                    $by,
                );
            }

            $correction->update([
                'status' => $approve ? TimeCorrection::STATUS_APPROVED : TimeCorrection::STATUS_REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_remarks' => $remarks,
            ]);

            return $correction->refresh();
        });
    }

    /**
     * Each employee's totals over a range — what payroll pays from, and what a
     * cutoff and a client timesheet show.
     *
     * Absences with approved leave behind them are not counted: a paid leave
     * is inside the salary, and an unpaid one is deducted once, as leave.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, array<string, float|int>>
     */
    public function summaries(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        $logs = AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->between($from->toDateString(), $to->toDateString())
            ->get()
            ->groupBy('employee_id');

        $overtime = OvertimeRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->selectRaw('employee_id, sum(hours) as hours')
            ->groupBy('employee_id')
            ->pluck('hours', 'employee_id');

        $covered = $this->leave->approvedLeaveDates($employeeIds, $from, $to);

        // Regular sorts before special, so a date that is both keeps the higher premium.
        $holidays = Holiday::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('type')
            ->get()
            ->unique(fn (Holiday $holiday) => $holiday->date->toDateString())
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => $holiday->type]);

        return collect($employeeIds)->mapWithKeys(function (int $id) use ($logs, $overtime, $covered, $holidays) {
            $days = $logs->get($id, collect());

            $absent = $days->filter(fn (AttendanceLog $log) => $log->status === AttendanceLog::STATUS_ABSENT
                && ! $covered->has($id.'|'.$log->work_date->toDateString()));

            // Hours actually worked on a holiday, capped at a normal day: past
            // that it is overtime, and overtime is paid only when approved.
            $holidayHours = fn (string $type) => round($days
                ->filter(fn (AttendanceLog $log) => ($holidays[$log->work_date->toDateString()] ?? null) === $type)
                ->sum(fn (AttendanceLog $log) => min($log->minutes_worked, 60 * (int) config('payroll.hours_per_day')) / 60), 2);

            return [$id => [
                'recorded_days' => $days->count(),
                'days_worked' => $days->whereIn('status', AttendanceLog::WORKED_STATUSES)->count(),
                'minutes_worked' => (int) $days->sum('minutes_worked'),
                'late_minutes' => (int) $days->sum('late_minutes'),
                'undertime_minutes' => (int) $days->sum('undertime_minutes'),
                'night_diff_minutes' => (int) $days->sum('night_diff_minutes'),
                'absent_days' => (float) $absent->count(),
                'incomplete_days' => $days->where('status', AttendanceLog::STATUS_INCOMPLETE)->count(),
                'overtime_hours' => round((float) ($overtime[$id] ?? 0), 2),
                'regular_holiday_hours' => $holidayHours(Holiday::TYPE_REGULAR),
                'special_holiday_hours' => $holidayHours(Holiday::TYPE_SPECIAL),
            ]];
        });
    }

    /** @return array<string, float|int> */
    public function summaryFor(Employee $employee, Carbon $from, Carbon $to): array
    {
        return $this->summaries([$employee->id], $from, $to)->get($employee->id);
    }

    public function periodRange(PayrollPeriod $period): array
    {
        return [$period->start_date->copy()->startOfDay(), $period->end_date->copy()->startOfDay()];
    }

    private function at(Carbon $date, ?string $time): ?Carbon
    {
        if ($time === null || trim($time) === '') {
            return null;
        }

        [$hour, $minute] = array_pad(array_map('intval', explode(':', trim($time))), 2, 0);

        return $date->copy()->setTime($hour, $minute);
    }

    private function timeOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('H:i');
    }
}
