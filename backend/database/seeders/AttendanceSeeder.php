<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Holiday;
use App\Models\Shift;
use App\Services\AttendanceCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gives every employee a weekday schedule and 45 days of realistic DTR
 * history, so the Timekeeping screen has something to show.
 *
 * The window has to comfortably exceed a full month: payroll periods are
 * semi-monthly, and a run whose period reaches past the earliest record does
 * not fail — it quietly pays fewer hours than were worked, which reads as a
 * calculation bug rather than as missing data.
 */
class AttendanceSeeder extends Seeder
{
    private const DAYS_OF_HISTORY = 45;

    public function run(AttendanceCalculator $calculator): void
    {
        /*
         * Fills the gaps rather than refusing to run.
         *
         * This used to bail out the moment any log existed, which meant a
         * database seeded when the window was shorter could never be topped
         * up — the only way to widen the history was to delete attendance
         * that payroll had already been computed from. Skipping per
         * employee-and-date instead makes the seeder safe to re-run and safe
         * to widen, the same property LeaveAccrualService::accrue() has.
         *
         * Keyed off a normalised date string: `log_date` is date-cast and
         * stores as "Y-m-d 00:00:00" on some drivers, so comparing raw column
         * values would miss and insert a duplicate.
         */
        $existing = AttendanceLog::query()
            ->get(['employee_id', 'log_date'])
            ->map(fn ($log) => $log->employee_id.'|'.Carbon::parse($log->log_date)->toDateString())
            ->flip();

        $dayShift = Shift::where('name', 'Day Shift')->first();
        $nightShift = Shift::where('name', 'Night Shift')->first();

        if (! $dayShift) {
            $this->command?->warn('No shifts found — run ShiftSeeder first.');

            return;
        }

        /*
         * `on_leave` is a state, not an exit — someone away this week still
         * worked the weeks before it, and payroll reads their DTR for the
         * period like anyone else's. Only `inactive` (separated) employees
         * have no history to give. Filtering on 'active' alone left three
         * people with no attendance at all, which reads as missing data
         * rather than as leave.
         */
        $employees = Employee::where('status', '!=', 'inactive')->get();
        $holidays = Holiday::pluck('date')->map(fn ($date) => Carbon::parse($date)->toDateString())->all();

        $rows = [];

        foreach ($employees->values() as $index => $employee) {
            // Every fifth employee runs nights; the rest are on days.
            $shift = ($nightShift && $index % 5 === 4) ? $nightShift : $dayShift;

            EmployeeSchedule::firstOrCreate(
                ['employee_id' => $employee->id, 'shift_id' => $shift->id],
                [
                    'effective_from' => Carbon::now()->subYear()->toDateString(),
                    'days_of_week' => [1, 2, 3, 4, 5],
                ],
            );

            // Runs through today (>= 0), so the dashboard's "today" figures are
            // populated the moment the seeder finishes.
            for ($back = self::DAYS_OF_HISTORY; $back >= 0; $back--) {
                $date = Carbon::today()->subDays($back);

                if ($existing->has($employee->id.'|'.$date->toDateString())) {
                    continue;
                }

                $isWeekend = $date->dayOfWeekIso >= 6;
                $isHoliday = in_array($date->toDateString(), $holidays, true);

                $punches = $this->punchesFor($date, $shift, $isWeekend, $isHoliday);

                $computed = $calculator->compute(
                    date: $date,
                    shift: $shift,
                    timeIn: $punches['in'],
                    timeOut: $punches['out'],
                    isRestDay: $isWeekend,
                    isHoliday: $isHoliday,
                );

                $rows[] = [
                    ...$computed,
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                    'log_date' => $date->toDateString(),
                    'time_in' => $punches['in'],
                    'time_out' => $punches['out'],
                    'source' => 'biometric',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendance_logs')->insert($chunk);
        }

        $this->command?->info('Seeded '.count($rows).' attendance records.');
    }

    /**
     * Produces believable punches: mostly on time, sometimes late, occasionally
     * absent, with a little overtime.
     *
     * @return array{in: ?Carbon, out: ?Carbon}
     */
    private function punchesFor(Carbon $date, Shift $shift, bool $isWeekend, bool $isHoliday): array
    {
        if ($isWeekend || $isHoliday) {
            return ['in' => null, 'out' => null];
        }

        // Roughly one absence per employee per month.
        if (random_int(1, 100) <= 4) {
            return ['in' => null, 'out' => null];
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', $shift->start_time));
        [$endHour, $endMinute] = array_map('intval', explode(':', $shift->end_time));

        $in = $date->copy()->setTime($startHour, $startMinute)
            ->addMinutes(random_int(-10, 100) > 70 ? random_int(16, 45) : random_int(-10, 10));

        $out = $date->copy()->setTime($endHour, $endMinute);

        if ($shift->crossesMidnight()) {
            $out->addDay();
        }

        // A quarter of days run a little over.
        $out->addMinutes(random_int(1, 100) <= 25 ? random_int(30, 180) : random_int(-5, 5));

        return ['in' => $in, 'out' => $out];
    }
}
