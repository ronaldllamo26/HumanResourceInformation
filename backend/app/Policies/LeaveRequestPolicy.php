<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LeaveRequest $request): bool
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
     * Filing leave.
     *
     * You file your own, so you have to be somebody the roster knows — an
     * account with no employee record has no credits, no rest days, and no
     * supervisor, and there is nothing for a request from it to be costed
     * against.
     *
     * HR is deliberately not exempt from that. It used to be: `isHrAdmin()`
     * passed on its own, and the form let HR pick anybody to file for. That
     * made HR both the filer and the approver of the same request, which is
     * the one thing the rest of this module is built to prevent — and it put
     * a "File Leave" button on a screen HR opens to *decide* on other
     * people's leave. An HR staff member who is also on the roster still
     * files their own leave here, like everybody else.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * Approving or declining — one step, and HR's alone.
     *
     * This was two steps: the employee's supervisor endorsed, then HR
     * confirmed, and only that second step moved credits. It is one step now
     * because the second signature was the only one that ever decided
     * anything, and asking a supervisor first bought a delay rather than a
     * decision.
     *
     * `supervisor_approved` is still accepted here, and that is not dead
     * code: rows sitting in it when the rule changed are real requests
     * somebody is waiting on. No new row ever enters that status.
     *
     * Nobody signs off on their own leave, HR included.
     */
    public function decide(User $user, LeaveRequest $request): bool
    {
        $awaiting = in_array($request->status, [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_SUPERVISOR_APPROVED,
        ], true);

        if (! $awaiting || $this->isOwn($user, $request)) {
            return false;
        }

        return $user->isHrAdmin();
    }

    /** Turning one down is the same decision, taken the other way. */
    public function reject(User $user, LeaveRequest $request): bool
    {
        return $this->decide($user, $request);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    public function manageTypes(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function adjustBalances(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** Nobody signs off on their own leave, HR included. */
    private function isOwn(User $user, LeaveRequest $request): bool
    {
        return $request->employee?->user_id === $user->id;
    }

    private function supervises(User $user, LeaveRequest $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
