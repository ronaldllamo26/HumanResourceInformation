<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Derives a day's attendance figures from raw punches and the assigned shift.
 *
 * Kept separate from TimekeepingService because Payroll depends on these numbers
 * being correct and reproducible — this class touches no database and is unit
 * tested in isolation.
 */
class AttendanceCalculator
{
    /** Night differential window under the Philippine Labor Code. */
    private const NIGHT_START_HOUR = 22;

    private const NIGHT_END_HOUR = 6;

    /**
     * @return array{
     *     status: string, hours_worked: float, late_minutes: int,
     *     undertime_minutes: int, overtime_minutes: int, night_diff_minutes: int
     * }
     */
    public function compute(
        Carbon $date,
        ?Shift $shift,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        ?Carbon $breakOut = null,
        ?Carbon $breakIn = null,
        bool $isRestDay = false,
        bool $isHoliday = false,
    ): array {
        $blank = [
            'hours_worked' => 0.0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'night_diff_minutes' => 0,
        ];

        // No time-in means nothing was worked; the day is classified by context.
        if (! $timeIn) {
            return [...$blank, 'status' => match (true) {
                $isHoliday => AttendanceLog::STATUS_HOLIDAY,
                $isRestDay => AttendanceLog::STATUS_REST_DAY,
                default => AttendanceLog::STATUS_ABSENT,
            }];
        }

        // Still clocked in — record the punch but derive nothing from it yet.
        if (! $timeOut) {
            return [...$blank, 'status' => AttendanceLog::STATUS_PRESENT];
        }

        $shiftStart = $shift ? $this->anchor($date, $shift->start_time) : null;
        $shiftEnd = $shift ? $this->shiftEnd($date, $shift) : null;

        $breakMinutes = $this->breakMinutes($shift, $breakOut, $breakIn);
        $workedMinutes = max(0, $timeIn->diffInMinutes($timeOut) - $breakMinutes);

        $late = 0;
        $undertime = 0;
        $overtime = 0;

        if ($shiftStart && $shiftEnd) {
            // Arriving inside the grace period is forgiven entirely; past it,
            // lateness is counted from the scheduled start.
            $graceCutoff = $shiftStart->copy()->addMinutes($shift->grace_period_minutes ?? 0);
            if ($timeIn->greaterThan($graceCutoff)) {
                $late = $shiftStart->diffInMinutes($timeIn);
            }

            if ($timeOut->lessThan($shiftEnd)) {
                $undertime = $timeOut->diffInMinutes($shiftEnd);
            }

            if ($timeOut->greaterThan($shiftEnd)) {
                // Raw time beyond the shift. Whether it is *paid* depends on an
                // approved OvertimeRequest — that gate lives in Payroll.
                $overtime = $shiftEnd->diffInMinutes($timeOut);
            }
        }

        return [
            'status' => $this->status($late, $undertime, $isRestDay, $isHoliday),
            'hours_worked' => round($workedMinutes / 60, 2),
            'late_minutes' => (int) $late,
            'undertime_minutes' => (int) $undertime,
            'overtime_minutes' => (int) $overtime,
            'night_diff_minutes' => $this->nightDifferentialMinutes($timeIn, $timeOut),
        ];
    }

    /**
     * Minutes of the worked period that fall inside 22:00–06:00.
     *
     * Windows are built per calendar day and never overlap, so summing them
     * cannot double count a shift that spans several nights.
     */
    public function nightDifferentialMinutes(Carbon $start, Carbon $end): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $minutes = 0;
        $cursor = $start->copy()->subDay()->startOfDay();
        $limit = $end->copy()->addDay()->startOfDay();

        while ($cursor->lessThanOrEqualTo($limit)) {
            $windowStart = $cursor->copy()->setTime(self::NIGHT_START_HOUR, 0);
            $windowEnd = $cursor->copy()->addDay()->setTime(self::NIGHT_END_HOUR, 0);

            $overlapStart = $start->greaterThan($windowStart) ? $start : $windowStart;
            $overlapEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;

            if ($overlapEnd->greaterThan($overlapStart)) {
                $minutes += $overlapStart->diffInMinutes($overlapEnd);
            }

            $cursor->addDay();
        }

        return (int) $minutes;
    }

    /** Resolves the shift's end, pushing it to the next day when it wraps midnight. */
    public function shiftEnd(Carbon $date, Shift $shift): Carbon
    {
        $end = $this->anchor($date, $shift->end_time);

        return $shift->crossesMidnight() ? $end->addDay() : $end;
    }

    private function anchor(Carbon $date, string $time): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $date->copy()->setTime((int) $hour, (int) $minute, 0);
    }

    /** Actual break when both punches exist, otherwise the shift's allowance. */
    private function breakMinutes(?Shift $shift, ?Carbon $breakOut, ?Carbon $breakIn): int
    {
        if ($breakOut && $breakIn && $breakIn->greaterThan($breakOut)) {
            return (int) $breakOut->diffInMinutes($breakIn);
        }

        return (int) ($shift->break_minutes ?? 0);
    }

    private function status(int $late, int $undertime, bool $isRestDay, bool $isHoliday): string
    {
        return match (true) {
            $isHoliday => AttendanceLog::STATUS_HOLIDAY,
            $isRestDay => AttendanceLog::STATUS_REST_DAY,
            $late > 0 => AttendanceLog::STATUS_LATE,
            $undertime > 0 => AttendanceLog::STATUS_UNDERTIME,
            default => AttendanceLog::STATUS_PRESENT,
        };
    }
}
