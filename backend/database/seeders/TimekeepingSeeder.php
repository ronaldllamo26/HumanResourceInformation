<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\TimeCorrection;
use App\Models\User;
use App\Services\TimekeepingService;
use App\Services\WorkCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Shifts, the fixed-date Philippine holidays, a schedule for everybody, and a
 * month of time records — so every Time & Attendance screen, and the payroll
 * run seeded after it, has something real to read.
 *
 * Every day goes through `TimekeepingService::record()`, the same door HR's
 * entries use, so the seeded figures are computed rather than invented.
 * Safe to re-run: each part skips what already exists.
 */
class TimekeepingSeeder extends Seeder
{
    private const DAYS_OF_HISTORY = 35;

    public function run(TimekeepingService $timekeeping, WorkCalendar $calendar): void
    {
        $shifts = $this->seedShifts();
        $this->seedHolidays();

        $employees = Employee::where('status', '!=', 'inactive')->orderBy('id')->get();

        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees->values() as $index => $employee) {
            if (EmployeeShift::where('employee_id', $employee->id)->exists()) {
                continue;
            }

            // Every fifth person runs nights; the rest are on days.
            $night = $index % 5 === 4;

            EmployeeShift::create([
                'employee_id' => $employee->id,
                'shift_id' => ($night ? $shifts['NIGHT'] : $shifts['DAY'])->id,
                'rest_days' => $night ? [7, 1] : [6, 7],
                'effective_from' => now()->subYear()->startOfMonth()->toDateString(),
            ]);
        }

        if (AttendanceLog::exists()) {
            return;
        }

        $hr = User::where('role', User::ROLE_HR_STAFF)->first();
        mt_srand(2026);

        foreach ($employees->values() as $index => $employee) {
            $night = $index % 5 === 4;

            for ($offset = self::DAYS_OF_HISTORY; $offset >= 1; $offset--) {
                $date = today()->subDays($offset);
                // A rest day or holiday is recorded as one, with nobody clocking in.
                [$in, $out] = $calendar->isWorkingDay($employee, $date)
                    ? $this->punches($night, mt_rand(1, 100))
                    : [null, null];

                $timekeeping->record($employee, $date, $in, $out, AttendanceLog::SOURCE_IMPORT, null, $hr);
            }
        }

        $this->seedRequests($employees);

        $this->command?->info('Seeded shifts, holidays, schedules and '.AttendanceLog::count().' time record(s).');
    }

    /** @return array<string, Shift> */
    private function seedShifts(): array
    {
        $definitions = [
            'DAY' => ['name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'grace_minutes' => 10],
            'MID' => ['name' => 'Mid Shift', 'start_time' => '13:00', 'end_time' => '22:00', 'break_minutes' => 60, 'grace_minutes' => 10],
            'NIGHT' => ['name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 60, 'grace_minutes' => 10],
        ];

        return collect($definitions)
            ->mapWithKeys(fn (array $attributes, string $code) => [
                $code => Shift::firstOrCreate(['code' => $code], $attributes + ['is_active' => true]),
            ])
            ->all();
    }

    /**
     * The fixed-date holidays (RA 9492 and later laws), this year and next.
     * Movable ones — Holy Week, the Eids, Chinese New Year — are proclaimed
     * each year and added by HR on the Holiday Calendar.
     */
    private function seedHolidays(): void
    {
        foreach ([now()->year, now()->year + 1] as $year) {
            $days = [
                ["$year-01-01", "New Year's Day", Holiday::TYPE_REGULAR],
                ["$year-02-25", 'EDSA People Power Revolution Anniversary', Holiday::TYPE_SPECIAL],
                ["$year-04-09", 'Araw ng Kagitingan', Holiday::TYPE_REGULAR],
                ["$year-05-01", 'Labor Day', Holiday::TYPE_REGULAR],
                ["$year-06-12", 'Independence Day', Holiday::TYPE_REGULAR],
                ["$year-08-21", 'Ninoy Aquino Day', Holiday::TYPE_SPECIAL],
                [Carbon::create($year, 8, 1)->lastOfMonth(Carbon::MONDAY)->toDateString(), 'National Heroes Day', Holiday::TYPE_REGULAR],
                ["$year-11-01", "All Saints' Day", Holiday::TYPE_SPECIAL],
                ["$year-11-02", "All Souls' Day", Holiday::TYPE_SPECIAL],
                ["$year-11-30", 'Bonifacio Day', Holiday::TYPE_REGULAR],
                ["$year-12-08", 'Feast of the Immaculate Conception of Mary', Holiday::TYPE_SPECIAL],
                ["$year-12-24", 'Christmas Eve', Holiday::TYPE_SPECIAL],
                ["$year-12-25", 'Christmas Day', Holiday::TYPE_REGULAR],
                ["$year-12-30", 'Rizal Day', Holiday::TYPE_REGULAR],
                ["$year-12-31", 'Last Day of the Year', Holiday::TYPE_SPECIAL],
            ];

            foreach ($days as [$date, $name, $type]) {
                if (! Holiday::whereDate('date', $date)->where('name', $name)->exists()) {
                    Holiday::create(['date' => $date, 'name' => $name, 'type' => $type]);
                }
            }
        }
    }

    /**
     * A plausible day: mostly on time, some late, a few short, the odd
     * absence and one forgotten time-out.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function punches(bool $night, int $roll): array
    {
        [$start, $end] = $night ? [22, 6] : [8, 17];
        $at = fn (int $hour, int $minutes) => sprintf('%02d:%02d', (($hour * 60 + $minutes + 1440) % 1440) / 60, ($hour * 60 + $minutes + 1440) % 60);

        return match (true) {
            $roll <= 3 => [null, null],                                                  // absent
            $roll === 4 => [$at($start, -5), null],                                      // forgot to clock out
            $roll <= 16 => [$at($start, mt_rand(12, 45)), $at($end, mt_rand(0, 10))],     // late
            $roll <= 21 => [$at($start, -mt_rand(0, 10)), $at($end, -mt_rand(20, 90))],   // left early
            $roll <= 29 => [$at($start, -mt_rand(0, 10)), $at($end, mt_rand(60, 150))],   // stayed late
            default => [$at($start, -mt_rand(0, 12)), $at($end, mt_rand(0, 15))],        // on time
        };
    }

    private function seedRequests($employees): void
    {
        $approver = User::where('role', User::ROLE_HR_STAFF)->first();

        // Overtime for the days somebody stayed past the shift: most approved, the latest still pending.
        AttendanceLog::query()
            ->where('overtime_minutes', '>=', 60)
            ->whereIn('status', AttendanceLog::WORKED_STATUSES)
            ->orderBy('work_date')
            ->get()
            ->each(function (AttendanceLog $log, int $index) use ($approver) {
                $recent = $log->work_date->greaterThanOrEqualTo(today()->subDays(4));

                OvertimeRequest::create([
                    'employee_id' => $log->employee_id,
                    'work_date' => $log->work_date->toDateString(),
                    'hours' => round(floor($log->overtime_minutes / 30) / 2, 2),
                    'reason' => ['Extended trip', 'Vehicle turnover delayed', 'Client asked to stay', 'Inventory count'][$index % 4],
                    'status' => $recent ? OvertimeRequest::STATUS_PENDING : ($index % 9 === 0 ? OvertimeRequest::STATUS_REJECTED : OvertimeRequest::STATUS_APPROVED),
                    'decided_by' => $recent ? null : $approver?->id,
                    'decided_at' => $recent ? null : $log->work_date->copy()->addDay()->setTime(9, 0),
                    'decision_remarks' => ! $recent && $index % 9 === 0 ? 'No prior approval from the supervisor.' : null,
                ]);
            });

        // A forgotten time-out, asked to be fixed.
        $incomplete = AttendanceLog::where('status', AttendanceLog::STATUS_INCOMPLETE)->orderByDesc('work_date')->first();

        if ($incomplete) {
            TimeCorrection::create([
                'employee_id' => $incomplete->employee_id,
                'work_date' => $incomplete->work_date->toDateString(),
                'time_in' => null,
                'time_out' => $incomplete->time_in?->copy()->addHours(9),
                'reason' => 'Forgot to clock out — the biometric was offline at the end of the shift.',
                'status' => TimeCorrection::STATUS_PENDING,
            ]);
        }
    }
}
