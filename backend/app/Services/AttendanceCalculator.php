<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Turns one day's punches and shift into the figures payroll pays from.
 *
 * No database: payroll multiplies these numbers by money, so every rule is
 * unit tested in isolation. The rules are the Labor Code's and the company's:
 *
 *  - Arriving inside the shift's grace minutes is not late; past it, lateness
 *    counts from the scheduled start, not from the end of the grace.
 *  - Leaving before the scheduled end is undertime; staying past it is raw
 *    overtime — whether it is *paid* is an approved overtime request's call.
 *  - Night differential is every minute worked between 22:00 and 06:00.
 *  - A shift ending at or before it starts (22:00–06:00) ends the next day.
 */
class AttendanceCalculator
{
    private const NIGHT_START_HOUR = 22;

    private const NIGHT_END_HOUR = 6;

    private const MEAL_PERIOD_AFTER_MINUTES = 300;

    /**
     * @return array{status: string, minutes_worked: int, late_minutes: int,
     *     undertime_minutes: int, overtime_minutes: int, night_diff_minutes: int}
     */
    public function compute(
        Carbon $date,
        ?Shift $shift,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        bool $isRestDay = false,
        bool $isHoliday = false,
    ): array {
        $blank = [
            'minutes_worked' => 0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'night_diff_minutes' => 0,
        ];

        // Nothing punched: the day is named by what kind of day it was.
        if (! $timeIn) {
            return [...$blank, 'status' => match (true) {
                $isHoliday => AttendanceLog::STATUS_HOLIDAY,
                $isRestDay => AttendanceLog::STATUS_REST_DAY,
                default => AttendanceLog::STATUS_ABSENT,
            }];
        }

        // In but not out: record it, compute nothing, and let payroll see it.
        if (! $timeOut) {
            return [...$blank, 'status' => AttendanceLog::STATUS_INCOMPLETE];
        }

        // A time-out earlier than the time-in means the shift ran past midnight.
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = $timeOut->copy()->addDay();
        }

        $breakMinutes = (int) ($shift?->break_minutes ?? 0);
        $spanMinutes = (int) $timeIn->diffInMinutes($timeOut);
        // The break only comes off a day long enough to have had one: the
        // Labor Code puts the meal period within the first five hours.
        $worked = $spanMinutes > self::MEAL_PERIOD_AFTER_MINUTES ? $spanMinutes - $breakMinutes : $spanMinutes;

        $late = 0;
        $undertime = 0;
        $overtime = 0;

        if ($shift && ! $isRestDay && ! $isHoliday) {
            $start = $this->at($date, $shift->start_time);
            $end = $this->shiftEnd($date, $shift);

            if ($timeIn->greaterThan($start->copy()->addMinutes((int) $shift->grace_minutes))) {
                $late = (int) $start->diffInMinutes($timeIn);
            }

            if ($timeOut->lessThan($end)) {
                $undertime = (int) $timeOut->diffInMinutes($end);
            } elseif ($timeOut->greaterThan($end)) {
                $overtime = (int) $end->diffInMinutes($timeOut);
            }
        } elseif ($isRestDay || $isHoliday) {
            // Every minute on a rest day or holiday is outside the schedule.
            $overtime = $worked;
        }

        return [
            'status' => match (true) {
                $isHoliday => AttendanceLog::STATUS_HOLIDAY,
                $isRestDay => AttendanceLog::STATUS_REST_DAY,
                $late > 0 => AttendanceLog::STATUS_LATE,
                $undertime > 0 => AttendanceLog::STATUS_UNDERTIME,
                default => AttendanceLog::STATUS_PRESENT,
            },
            'minutes_worked' => max(0, $worked),
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
            'overtime_minutes' => $overtime,
            'night_diff_minutes' => $this->nightDifferentialMinutes($timeIn, $timeOut),
        ];
    }

    /**
     * Minutes of a worked span that fall between 22:00 and 06:00.
     *
     * Built one night at a time, and the nights never overlap, so a span
     * crossing several of them cannot be counted twice.
     */
    public function nightDifferentialMinutes(Carbon $start, Carbon $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $minutes = 0;
        $cursor = $start->copy()->subDay()->startOfDay();
        $limit = $end->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($limit)) {
            $windowStart = $cursor->copy()->setTime(self::NIGHT_START_HOUR, 0);
            $windowEnd = $cursor->copy()->addDay()->setTime(self::NIGHT_END_HOUR, 0);

            $from = $start->greaterThan($windowStart) ? $start : $windowStart;
            $to = $end->lessThan($windowEnd) ? $end : $windowEnd;

            if ($to->greaterThan($from)) {
                $minutes += (int) $from->diffInMinutes($to);
            }

            $cursor->addDay();
        }

        return $minutes;
    }

    public function shiftEnd(Carbon $date, Shift $shift): Carbon
    {
        $end = $this->at($date, $shift->end_time);

        return $shift->crossesMidnight() ? $end->addDay() : $end;
    }

    private function at(Carbon $date, string $time): Carbon
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $date->copy()->startOfDay()->setTime($hour, $minute);
    }
}
