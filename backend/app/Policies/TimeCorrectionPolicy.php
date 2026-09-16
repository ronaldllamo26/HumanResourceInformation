<?php

namespace App\Policies;

use App\Models\TimeCorrection;
use App\Models\User;

/**
 * A correction is how anybody but HR changes a day — and HR's own days too.
 * Same shape as overtime: file your own, somebody else decides.
 */
class TimeCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TimeCorrection $correction): bool
    {
        return $user->isHrAdmin() || $this->owns($user, $correction) || $this->supervises($user, $correction);
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    public function decide(User $user, TimeCorrection $correction): bool
    {
        return $correction->isPending()
            && ! $this->owns($user, $correction)
            && ($user->isHrAdmin() || $this->supervises($user, $correction));
    }

    public function cancel(User $user, TimeCorrection $correction): bool
    {
        return $correction->isPending() && $this->owns($user, $correction);
    }

    private function owns(User $user, TimeCorrection $correction): bool
    {
        return $correction->employee?->user_id !== null && $correction->employee->user_id === $user->id;
    }

    private function supervises(User $user, TimeCorrection $correction): bool
    {
        return $user->isSupervisor()
            && $correction->employee?->supervisor_id !== null
            && $correction->employee->supervisor_id === $user->employee?->id;
    }
}
