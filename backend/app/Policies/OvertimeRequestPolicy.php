<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;

/**
 * Overtime is filed by the person who worked it and decided by somebody else.
 *
 * HR is not exempt from filing its own: approved hours go straight onto a
 * payslip, so being able to file for anybody would make HR both the claimant
 * and an approver of the same claim.
 */
class OvertimeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OvertimeRequest $request): bool
    {
        return $user->isHrAdmin() || $this->owns($user, $request) || $this->supervises($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    /** HR or the requester's own supervisor — never the requester. */
    public function decide(User $user, OvertimeRequest $request): bool
    {
        return $request->isPending()
            && ! $this->owns($user, $request)
            && ($user->isHrAdmin() || $this->supervises($user, $request));
    }

    public function cancel(User $user, OvertimeRequest $request): bool
    {
        return $request->isPending() && $this->owns($user, $request);
    }

    private function owns(User $user, OvertimeRequest $request): bool
    {
        return $request->employee?->user_id !== null && $request->employee->user_id === $user->id;
    }

    private function supervises(User $user, OvertimeRequest $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
