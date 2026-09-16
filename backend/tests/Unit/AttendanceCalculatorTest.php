<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Services\AttendanceCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pure arithmetic — payroll multiplies these numbers by money, so each rule
 * gets its own case. The framework is booted only because unsaved Eloquent
 * models still want a connection resolver.
 */
class AttendanceCalculatorTest extends TestCase
{
    private AttendanceCalculator $calculator;

    private Carbon $date;

    public function test_a_full_on_time_day_has_nothing_to_deduct(): void
    {
        $result = $this->compute('08:00', '17:00');

        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
        $this->assertSame(480, $result['minutes_worked']); // nine hours less the hour's break
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
        $this->assertSame(0, $result['night_diff_minutes']);
    }

    public function test_arriving_inside_the_grace_period_is_not_late(): void
    {
        $result = $this->compute('08:10', '17:00');

        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
    }

    public function test_past_the_grace_period_lateness_counts_from_the_shift_start(): void
    {
        $result = $this->compute('08:20', '17:00');

        // 20 minutes, not 10 — the grace forgives, it does not offset.
        $this->assertSame(20, $result['late_minutes']);
        $this->assertSame(AttendanceLog::STATUS_LATE, $result['status']);
    }

    public function test_leaving_early_is_undertime(): void
    {
        $result = $this->compute('08:00', '16:30');

        $this->assertSame(30, $result['undertime_minutes']);
        $this->assertSame(AttendanceLog::STATUS_UNDERTIME, $result['status']);
        $this->assertSame(450, $result['minutes_worked']);
    }

    public function test_staying_past_the_shift_is_raw_overtime(): void
    {
        $result = $this->compute('08:00', '19:30');

        $this->assertSame(150, $result['overtime_minutes']);
        $this->assertSame(AttendanceLog::STATUS_PRESENT, $result['status']);
    }

    public function test_no_time_in_is_named_by_the_kind_of_day(): void
    {
        $this->assertSame(AttendanceLog::STATUS_ABSENT, $this->calculator->compute($this->date, $this->dayShift(), null, null)['status']);
        $this->assertSame(AttendanceLog::STATUS_REST_DAY, $this->calculator->compute($this->date, $this->dayShift(), null, null, isRestDay: true)['status']);
        $this->assertSame(AttendanceLog::STATUS_HOLIDAY, $this->calculator->compute($this->date, $this->dayShift(), null, null, true, true)['status']);
    }

    public function test_a_missing_time_out_computes_nothing(): void
    {
        $result = $this->compute('08:45', null);

        $this->assertSame(AttendanceLog::STATUS_INCOMPLETE, $result['status']);
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0, $result['minutes_worked']);
    }

    public function test_a_night_shift_ends_the_next_morning(): void
    {
        $result = $this->calculator->compute(
            $this->date,
            $this->shift('22:00', '06:00'),
            $this->date->copy()->setTime(22, 0),
            $this->date->copy()->addDay()->setTime(6, 0),
        );

        $this->assertSame(420, $result['minutes_worked']); // eight hours less the break
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertSame(480, $result['night_diff_minutes']); // every minute 22:00–06:00
    }

    public function test_a_time_out_earlier_than_the_time_in_is_the_next_day(): void
    {
        $result = $this->calculator->compute(
            $this->date,
            $this->shift('22:00', '06:00'),
            $this->date->copy()->setTime(22, 0),
            $this->date->copy()->setTime(6, 0),
        );

        $this->assertSame(420, $result['minutes_worked']);
    }

    public function test_work_on_a_rest_day_is_all_outside_the_schedule(): void
    {
        $result = $this->calculator->compute(
            $this->date,
            $this->dayShift(),
            $this->date->copy()->setTime(9, 0),
            $this->date->copy()->setTime(13, 0),
            isRestDay: true,
        );

        $this->assertSame(AttendanceLog::STATUS_REST_DAY, $result['status']);
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(240, $result['overtime_minutes']); // too short for the break to come off
    }

    public function test_night_differential_is_not_double_counted_across_nights(): void
    {
        $start = Carbon::parse('2026-08-04 20:00');
        $end = Carbon::parse('2026-08-06 08:00');

        // Two full nights of 8 hours each.
        $this->assertSame(960, $this->calculator->nightDifferentialMinutes($start, $end));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new AttendanceCalculator;
        $this->date = Carbon::parse('2026-08-04'); // a Tuesday
    }

    /** @return array<string, mixed> */
    private function compute(string $in, ?string $out): array
    {
        return $this->calculator->compute(
            $this->date,
            $this->dayShift(),
            $this->date->copy()->setTimeFromTimeString($in),
            $out ? $this->date->copy()->setTimeFromTimeString($out) : null,
        );
    }

    private function dayShift(): Shift
    {
        return $this->shift('08:00', '17:00');
    }

    private function shift(string $start, string $end): Shift
    {
        return new Shift(['code' => 'T', 'name' => 'Test', 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 60, 'grace_minutes' => 10]);
    }
}
