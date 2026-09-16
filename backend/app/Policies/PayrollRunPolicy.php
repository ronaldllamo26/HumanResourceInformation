<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

class PayrollRunPolicy
{
    /** HR runs payroll; employees reach their payslips by another route. */
    public function viewAny(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->isHrAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** A draft can be recomputed; anything further is locked. */
    public function update(User $user, PayrollRun $run): bool
    {
        return $user->isHrAdmin() && $run->isEditable();
    }

    public function submit(User $user, PayrollRun $run): bool
    {
        return $user->isHrAdmin() && $run->status === PayrollRun::STATUS_DRAFT;
    }

    /**
     * Separation of duties: only an admin approves, and never the same person
     * who processed the run. Payroll is the one place this matters most.
     */
    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->isAdmin()
            && $run->status === PayrollRun::STATUS_FOR_APPROVAL
            && $run->processed_by !== $user->id;
    }

    /**
     * Confirming the money went out — never by the person who computed the run.
     *
     * Approval already excluded the processor; marking paid did not, so the
     * one person who chose every figure could also be the one who says the
     * transfer matched them. Three hands now: compute, approve, confirm.
     */
    public function markPaid(User $user, PayrollRun $run): bool
    {
        return $user->isHrAdmin()
            && $run->status === PayrollRun::STATUS_APPROVED
            && $run->processed_by !== $user->id;
    }

    public function cancel(User $user, PayrollRun $run): bool
    {
        return $user->isAdmin() && ! $run->isFinal();
    }

    public function delete(User $user, PayrollRun $run): bool
    {
        return $user->isHrAdmin() && $run->isEditable();
    }

    public function manageCompensation(User $user): bool
    {
        return $user->isHrAdmin();
    }
}
