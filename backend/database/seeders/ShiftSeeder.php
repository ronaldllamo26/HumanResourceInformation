<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Baseline shifts and the fixed-date Philippine holidays for 2026–2027
 * (Module 2). Idempotent: safe to re-run, since each row is matched on
 * (date, name) — the table's own unique key.
 */
class ShiftSeeder extends Seeder
{
    private const SHIFTS = [
        ['Day Shift', '08:00', '17:00', 60, 15, false],
        ['Early Dispatch', '06:00', '15:00', 60, 15, false],
        ['Mid Shift', '14:00', '23:00', 60, 15, false],
        ['Night Shift', '22:00', '07:00', 60, 15, true],
    ];

    private const HOLIDAYS = [
        ['New Year\'s Day', '2026-01-01', 'regular'],
        ['Araw ng Kagitingan', '2026-04-09', 'regular'],
        ['Labor Day', '2026-05-01', 'regular'],
        ['Independence Day', '2026-06-12', 'regular'],
        ['National Heroes Day', '2026-08-31', 'regular'],
        ['Bonifacio Day', '2026-11-30', 'regular'],
        ['Christmas Day', '2026-12-25', 'regular'],
        ['Rizal Day', '2026-12-30', 'regular'],
        ['Ninoy Aquino Day', '2026-08-21', 'special_non_working'],
        ['All Saints\' Day', '2026-11-01', 'special_non_working'],
        ['Feast of the Immaculate Conception', '2026-12-08', 'special_non_working'],
        ['Last Day of the Year', '2026-12-31', 'special_non_working'],

        // 2027 — the fixed-date holidays under RA 9492, so the calendar does
        // not lapse at year end. Leave costing and attendance read this table
        // directly, and a year with nothing in it charges employees leave
        // credits for days they should not be charged for.
        //
        // Movable holidays (Maundy Thursday, Good Friday, and the two Eids)
        // are deliberately absent: they follow the liturgical and lunar
        // calendars and are fixed by annual proclamation, so HR adds them from
        // Timekeeping → Holidays once Malacañang publishes them.
        ['New Year\'s Day', '2027-01-01', 'regular'],
        ['Araw ng Kagitingan', '2027-04-09', 'regular'],
        ['Labor Day', '2027-05-01', 'regular'],
        ['Independence Day', '2027-06-12', 'regular'],
        ['National Heroes Day', '2027-08-30', 'regular'],   // last Monday of August
        ['Bonifacio Day', '2027-11-30', 'regular'],
        ['Christmas Day', '2027-12-25', 'regular'],
        ['Rizal Day', '2027-12-30', 'regular'],
        ['Ninoy Aquino Day', '2027-08-21', 'special_non_working'],
        ['All Saints\' Day', '2027-11-01', 'special_non_working'],
        ['Feast of the Immaculate Conception', '2027-12-08', 'special_non_working'],
        ['Last Day of the Year', '2027-12-31', 'special_non_working'],
    ];

    public function run(): void
    {
        foreach (self::SHIFTS as [$name, $start, $end, $break, $grace, $isNight]) {
            DB::table('shifts')->updateOrInsert(
                ['name' => $name],
                [
                    'start_time' => $start,
                    'end_time' => $end,
                    'break_minutes' => $break,
                    'grace_period_minutes' => $grace,
                    'is_night_shift' => $isNight,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        foreach (self::HOLIDAYS as [$name, $date, $type]) {
            DB::table('holidays')->updateOrInsert(
                ['date' => $date, 'name' => $name],
                [
                    'type' => $type,
                    'is_nationwide' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
