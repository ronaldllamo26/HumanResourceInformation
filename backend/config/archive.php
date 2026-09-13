<?php

/*
|--------------------------------------------------------------------------
| Archive & restore
|--------------------------------------------------------------------------
| What happens to a record after someone deletes it.
|
| Nothing in this system is destroyed by a delete button. Employees and
| clients are soft-deleted: the row stays, leaves the working lists, and shows
| on the archive screen where it can be restored. Config-driven like the rest,
| so widening the window is an edit here rather than a code change.
*/

return [

    /*
    | How long a deleted record is offered for one-click restore.
    |
    | Presentational, not a deadline — see `purge_after_days` below. Past this
    | the row is still there and still restorable; the screen just stops
    | treating it as a recent mistake and starts treating it as history.
    */
    'restore_window_days' => (int) env('ARCHIVE_RESTORE_WINDOW_DAYS', 30),

    /*
    | Deliberately null: nothing is ever purged automatically.
    |
    | It is tempting to read "keep it 30 days" as "delete it on day 31", and
    | for an HRIS that would be wrong twice over. Employment records must be
    | kept three years under the Labor Code's implementing rules, and payroll
    | records ten years under the NIRC as amended — an employee row is the
    | anchor for payslips, contributions, and BIR alphalists that long outlive
    | any retention window a UI would offer.
    |
    | So the window above governs how the screen *reads*, and the data itself
    | stays. If a deployment ever genuinely needs purging, that is a decision
    | someone makes deliberately with the statutory periods in front of them,
    | not a scheduled job quietly deleting evidence.
    */
    'purge_after_days' => null,

];
