<?php

namespace App\Policies;

use App\Models\DisciplinaryAction;
use App\Models\User;

/**
 * Who may record a disciplinary action, and who may read one.
 *
 * **Recording one is HR's, and there is no supervisor exemption.** A
 * supervisor is usually the person who *reports* the incident, and letting
 * them also file the sanction would put the complaint and the penalty in one
 * pair of hands — the same separation the rest of this system keeps: an
 * employee files their own overtime and somebody else decides it, HR prepares
 * a payroll run and an admin approves it, and nobody signs off their own
 * leave.
 *
 * Core 4 reaches this over the API with an HR-scoped token, which is why the
 * ability is checked on the class rather than on a record: it is asked before
 * any action exists.
 */
class DisciplinaryActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Reading one follows the *employee's* gate, not this class's.
     *
     * An action is a fact about somebody's employment, so whoever may open
     * that 201 file may read what is on it — which lets a supervisor see a
     * warning on their own report, and lets the employee see their own. The
     * controller authorises against the employee for exactly that reason.
     */
    public function view(User $user, DisciplinaryAction $action): bool
    {
        return $user->can('view', $action->employee);
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Admin only, and deliberately narrower than creating one.
     *
     * A sanction that the person who issued it can quietly rewrite is not a
     * record of anything — the same reason a decided endorsement is closed and
     * a released final pay freezes its figures. Correcting one is a new action
     * with its own reason, which leaves both on the record.
     */
    public function update(User $user, DisciplinaryAction $action): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, DisciplinaryAction $action): bool
    {
        return $user->isAdmin();
    }
}
