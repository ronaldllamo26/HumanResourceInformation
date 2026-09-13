<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;

class OvertimeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OvertimeRequest $request): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        $employee = $request->employee;

        if ($employee?->user_id === $user->id) {
            return true;
        }

        return $this->supervises($user, $request);
    }

    /**
     * Everybody files their own, HR included.
     *
     * This used to exempt `isHrAdmin()`, and the exemption made HR both the
     * filer and an approver of the same request — the one thing the rest of
     * this module is built to prevent. It also meant an overtime claim could
     * be entered by somebody who was not there, against a person who never
     * asked for it, and paid out of `PayrollService::approvedOvertimeHours()`
     * with nothing on the record saying whose account it was.
     *
     * The same line LeaveRequestPolicy::create draws, for the same reason.
     */
    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    /**
     * Only a pending request can still be edited, and only by its owner.
     *
     * HR is deliberately outside this now. A request says what somebody
     * claims they worked, which only the person who was there can restate;
     * HR that disagrees has `decide()`, and rejecting with a reason leaves a
     * record where a silent edit would leave none.
     */
    public function update(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        return $request->employee?->user_id === $user->id;
    }

    /**
     * Approvers are HR and the employee's own supervisor — never the requester,
     * so nobody signs off on their own overtime.
     */
    public function decide(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        if ($request->employee?->user_id === $user->id) {
            return false;
        }

        return $user->isHrAdmin() || $this->supervises($user, $request);
    }

    public function cancel(User $user, OvertimeRequest $request): bool
    {
        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        return $user->isHrAdmin() || $request->employee?->user_id === $user->id;
    }

    private function supervises(User $user, OvertimeRequest $request): bool
    {
        return $user->isSupervisor()
            && $request->employee?->supervisor_id !== null
            && $request->employee->supervisor_id === $user->employee?->id;
    }
}
