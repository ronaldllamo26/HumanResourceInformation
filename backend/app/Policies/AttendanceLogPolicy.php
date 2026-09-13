<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\User;

class AttendanceLogPolicy
{
    /** Everyone reaches the DTR screen; the query decides whose rows appear. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AttendanceLog $log): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        $employee = $log->employee;

        if ($employee?->user_id === $user->id) {
            return true;
        }

        return $user->isSupervisor()
            && $employee?->supervisor_id !== null
            && $employee->supervisor_id === $user->employee?->id;
    }

    /** Only HR may enter or correct time records — payroll is computed from them. */
    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function update(User $user, AttendanceLog $log): bool
    {
        return $user->isHrAdmin();
    }

    public function delete(User $user, AttendanceLog $log): bool
    {
        return $user->isHrAdmin();
    }
}
