<?php

/*
|--------------------------------------------------------------------------
| Timekeeping exception thresholds
|--------------------------------------------------------------------------
| What counts as "worth a second look" is a policy call, not a code change —
| HR tunes these here. AttendanceExceptionScanner reads everything from this
| file, so raising a threshold is a config edit.
*/

return [

    /*
    | A single day's lateness beyond this many minutes is flagged on its own,
    | separate from the "frequent lateness" pattern below.
    */
    'late_minutes_threshold' => 60,

    /*
    | A single day's overtime beyond this many hours is flagged — often a typo
    | in a punch rather than genuine overtime, worth a look before it is paid.
    */
    'overtime_hours_threshold' => 4,

    /*
    | Being late this many times inside the filtered range is a pattern, even
    | if no single day crosses the per-day threshold above.
    */
    'frequent_late_count' => 3,

    /*
    | Absent this many times inside the filtered range.
    */
    'frequent_absence_count' => 3,

    /*
    | A time-in with no time-out, on a day before today, is always flagged —
    | it is either a forgotten punch-out or a device that missed the tap.
    */
    'stale_open_punch_days' => 0,
];
