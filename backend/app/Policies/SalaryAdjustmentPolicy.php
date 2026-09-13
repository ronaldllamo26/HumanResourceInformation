<?php

namespace App\Policies;

use App\Models\SalaryAdjustment;
use App\Models\User;

/**
 * Salary is sensitive: the same bar as `EmployeePolicy::viewSensitive`, which
 * already keeps rates away from supervisors even for their own reports.
 *
 * Deleting is admin-only. Removing an adjustment re-points the employee's
 * current rate at the one before it, which is a change to what they are paid
 * — not a tidy-up.
 */
class SalaryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function view(User $user, SalaryAdjustment $adjustment): bool
    {
        return $user->isHrAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function delete(User $user, SalaryAdjustment $adjustment): bool
    {
        return $user->isAdmin();
    }
}
