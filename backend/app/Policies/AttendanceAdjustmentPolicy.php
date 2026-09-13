<?php

namespace App\Policies;

use App\Models\AttendanceAdjustment;
use App\Models\User;

/**
 * Who may ask for a DTR correction, and who may allow one.
 *
 * The same split the overtime queue draws, for the same reason: the person
 * asking for a change to their own time record may not be the person who
 * approves it.
 */
class AttendanceAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AttendanceAdjustment $request): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        if ($request->employee?->user_id === $user->id) {
            return true;
        }

        return $this->supervises($user, $request);
    }

    /**
     * Everybody files their own.
     *
     * HR has no exemption and needs none: HR can already write a time record
     * directly on Records, so a request queue exists for the people who
     * cannot. Letting HR file one would make the same person the requester
     * and an approver of the request — the thing this queue is here to
     * prevent — while adding nothing they could not already do.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    /** Only while it is still open, and only by the person who filed it. */
    public function update(User $user, AttendanceAdjustment $request): bool
    {
        return $request->isPending() && $request->employee?->user_id === $user->id;
    }

    /**
     * Approvers are HR and the employee's own supervisor — never the
     * requester, so nobody signs off on a correction to their own DTR.
     */
    public function decide(User $user, AttendanceAdjustment $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        if ($request->employee?->user_id === $user->id) {
            return false;
        }

        return $user->isHrAdmin() || $this->supervises($user, $request);
    }

    public function cancel(User $user, AttendanceAdjustment $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    private function supervises(User $user, AttendanceAdjustment $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
