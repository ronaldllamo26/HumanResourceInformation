<?php

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;

class PayslipPolicy
{
    /** Everyone can reach the payslip list; the query decides whose. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Payslip $payslip): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        // Employees see their own — but only once the run is final. A draft is
        // still being corrected and its figures are not yet real.
        return $payslip->employee?->user_id === $user->id && $payslip->run?->isFinal();
    }
}
