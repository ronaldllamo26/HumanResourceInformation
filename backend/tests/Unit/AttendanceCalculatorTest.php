<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Services\AttendanceCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure arithmetic — nothing here touches the database, but the framework is
 * booted because unsaved Eloquent models still need a connection resolver.
 * Payroll multiplies these numbers by money, so each rule gets its own case.
 */
class AttendanceCalculatorTest extends TestCase
{
    private AttendanceCalculator $calculator;

    private Carbon $date;

    public function test_a_full_on_time_day_has_no_deductions(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(), $this->at('08:00'), $this->at('17:00'),
        );

        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
        $this->assertSame(8.0, $result['hours_worked']); // 9 hours less a 60-minute break
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
    }

    public function test_arriving_inside_the_grace_period_is_not_late(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(15), $this->at('08:14'), $this->at('17:00'),
        );

        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
    }

    public function test_past_the_grace_period_lateness_counts_from_the_shift_start(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(15), $this->at('08:20'), $this->at('17:00'),
        );

        // 20 minutes late, not 5 — the grace period forgives, it does not offset.
        $this->assertSame(20, $result['late_minutes']);
        $this->assertSame(AttendanceLog::STATUS_LATE, $result['status']);
    }

    public function test_leaving_early_records_undertime(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(), $this->at('08:00'), $this->at('16:30'),
        );

        $this->assertSame(30, $result['undertime_minutes']);
        $this->assertSame(AttendanceLog::STATUS_UNDERTIME, $result['status']);
        $this->assertSame(7.5, $result['hours_worked']);
    }

    public function test_staying_past_the_shift_records_overtime(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(), $this->at('08:00'), $this->at('19:30'),
        );

        $this->assertSame(150, $result['overtime_minutes']);
        $this->assertSame(0, $result['undertime_minutes']);
    }

    public function test_no_time_in_is_an_absence(): void
    {
        $result = $this->calculator->compute($this->date, $this->dayShift(), null, null);

        $this->assertSame(AttendanceLog::STATUS_ABSENT, $result['status']);
        $this->assertSame(0.0, $result['hours_worked']);
    }

    public function test_a_missing_time_out_derives_nothing(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(), $this->at('08:45'), null,
        );

        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0.0, $result['hours_worked']);
    }

    public function test_rest_days_and_holidays_override_the_absence_status(): void
    {
        $restDay = $this->calculator->compute(
            $this->date, null, null, null, isRestDay: true,
        );
        $holiday = $this->calculator->compute(
            $this->date, null, null, null, isHoliday: true,
        );

        $this->assertSame(AttendanceLog::STATUS_REST_DAY, $restDay['status']);
        $this->assertSame(AttendanceLog::STATUS_HOLIDAY, $holiday['status']);
    }

    public function test_a_night_shift_crossing_midnight_is_measured_correctly(): void
    {
        $result = $this->calculator->compute(
            $this->date,
            $this->nightShift(),
            $this->at('22:00'),
            $this->at('07:00', 1),
        );

        $this->assertSame(8.0, $result['hours_worked']); // 9 hours less the break
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
        // The whole 22:00-06:00 window falls inside the shift.
        $this->assertSame(480, $result['night_diff_minutes']);
    }

    public function test_a_day_shift_earns_no_night_differential(): void
    {
        $result = $this->calculator->compute(
            $this->date, $this->dayShift(), $this->at('08:00'), $this->at('17:00'),
        );

        $this->assertSame(0, $result['night_diff_minutes']);
    }

    public function test_night_differential_counts_only_the_hours_inside_the_window(): void
    {
        // 20:00 to 02:00 — only 22:00 onward qualifies, so 4 of the 6 hours.
        $minutes = $this->calculator->nightDifferentialMinutes(
            $this->at('20:00'),
            $this->at('02:00', 1),
        );

        $this->assertSame(240, $minutes);
    }

    public function test_actual_break_punches_override_the_shift_allowance(): void
    {
        $result = $this->calculator->compute(
            $this->date,
            $this->dayShift(),
            $this->at('08:00'),
            $this->at('17:00'),
            breakOut: $this->at('12:00'),
            breakIn: $this->at('12:30'),
        );

        // A 30-minute break taken instead of the 60-minute allowance.
        $this->assertSame(8.5, $result['hours_worked']);
    }

    public function test_a_shift_end_that_wraps_midnight_resolves_to_the_next_day(): void
    {
        $end = $this->calculator->shiftEnd($this->date, $this->nightShift());

        $this->assertSame('2026-03-11 07:00', $end->format('Y-m-d H:i'));
    }

    public function test_without_a_shift_hours_are_still_counted(): void
    {
        $result = $this->calculator->compute(
            $this->date, null, $this->at('09:00'), $this->at('18:00'),
        );

        $this->assertSame(9.0, $result['hours_worked']); // no shift, so no break deduction
        $this->assertSame(0, $result['late_minutes']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new AttendanceCalculator;
        $this->date = Carbon::parse('2026-03-10')->startOfDay(); // a Tuesday
    }

    private function dayShift(int $grace = 15): Shift
    {
        return new Shift([
            'name' => 'Day Shift',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'grace_period_minutes' => $grace,
        ]);
    }

    private function nightShift(): Shift
    {
        return new Shift([
            'name' => 'Night Shift',
            'start_time' => '22:00',
            'end_time' => '07:00',
            'break_minutes' => 60,
            'grace_period_minutes' => 15,
        ]);
    }

    private function at(string $time, int $addDays = 0): Carbon
    {
        [$h, $m] = explode(':', $time);

        return $this->date->copy()->addDays($addDays)->setTime((int) $h, (int) $m);
    }
}
