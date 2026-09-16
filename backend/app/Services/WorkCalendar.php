<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which days are working days, for whom.
 *
 * Shared by Time & Attendance (is an absence really an absence?), Leave (does
 * this day cost a credit?) and Payroll (does this day earn a premium?), so the
 * three cannot disagree about the same Tuesday. Holds no state beyond a small
 * per-request memo, since a cutoff asks the same question hundreds of times.
 */
class WorkCalendar
{
    /** Rest days when an employee has no shift assigned: Saturday and Sunday. */
    public const DEFAULT_REST_DAYS = [6, 7];

    /** @var array<string, Holiday|null> */
    private array $holidays = [];

    /** @var array<int, Collection<int, EmployeeShift>> */
    private array $schedules = [];

    public function holidayOn(Carbon $date): ?Holiday
    {
        $key = $date->toDateString();

        if (! array_key_exists($key, $this->holidays)) {
            // Regular sorts before special, so a day that is both pays the higher premium.
            $this->holidays[$key] = Holiday::whereDate('date', $key)->orderBy('type')->first();
        }

        return $this->holidays[$key];
    }

    /** The shift assignment in force on a date, the latest one that has started. */
    public function scheduleFor(Employee $employee, Carbon $date): ?EmployeeShift
    {
        $this->schedules[$employee->id] ??= EmployeeShift::with('shift')
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->get();

        return $this->schedules[$employee->id]->first(
            fn (EmployeeShift $schedule) => $schedule->effective_from->lte($date)
                && ($schedule->effective_to === null || $schedule->effective_to->gte($date)),
        );
    }

    public function isRestDay(Employee $employee, Carbon $date): bool
    {
        $schedule = $this->scheduleFor($employee, $date);

        return $schedule
            ? $schedule->isRestDay($date)
            : in_array($date->isoWeekday(), self::DEFAULT_REST_DAYS, true);
    }

    public function isWorkingDay(Employee $employee, Carbon $date): bool
    {
        return ! $this->holidayOn($date) && ! $this->isRestDay($employee, $date);
    }

    /** Forget the memo — after a holiday or a schedule is saved in the same request. */
    public function flush(): void
    {
        $this->holidays = [];
        $this->schedules = [];
    }
}
