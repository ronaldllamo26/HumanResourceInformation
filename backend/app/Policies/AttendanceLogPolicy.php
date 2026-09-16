<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\User;

/**
 * Time & Attendance's own screens.
 *
 * Reading follows the employee's record (HR everybody, a supervisor the team,
 * everyone their own), narrowed by `TimekeepingService::scopedLogs()`. Writing
 * a day is HR's alone: a DTR somebody can rewrite about themselves is not a
 * record of anything, which is why everybody else files a correction.
 *
 * Shifts, holidays, cutoffs and client timesheets are asked of this class too
 * (`manage`, `closeCutoff`, `reopenCutoff`) — they are all "the people who keep
 * the time records", and one place to say who that is cannot drift.
 */
class AttendanceLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AttendanceLog $log): bool
    {
        if ($user->isHrAdmin() || $log->employee?->user_id === $user->id) {
            return true;
        }

        return $user->isSupervisor()
            && $log->employee?->supervisor_id !== null
            && $log->employee->supervisor_id === $user->employee?->id;
    }

    /** Recording, importing and deleting days; shifts, holidays and client timesheets. */
    public function manage(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function closeCutoff(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Reopening a closed cutoff is an admin's, with a reason: it lets figures
     * payroll may already have computed from move again.
     */
    public function reopenCutoff(User $user): bool
    {
        return $user->isAdmin();
    }
}
