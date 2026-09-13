<?php

namespace App\Policies;

use App\Models\EmployeeEndorsement;
use App\Models\User;

/**
 * Who may work the Core 1 inbox.
 *
 * Deliberately the same answer as `EmployeePolicy::create` rather than a new
 * rule: approving an endorsement *is* creating an employee — it is the only
 * way one gets created now — so the people allowed to do it must be exactly
 * the people who were allowed before. Moving the door does not change who
 * holds the key, the same reasoning that kept Departments and Positions on
 * `manageOrganization` when they moved out of Settings.
 *
 * Written as `isHrAdmin()` in both places for that reason. If the two ever
 * need to differ, that is a decision to take deliberately — not something to
 * arrive at because one of them was edited and the other was not.
 */
class EmployeeEndorsementPolicy
{
    /**
     * The inbox itself.
     *
     * Class-level, like `EmployeePolicy::viewArchive`: it is asked before any
     * endorsement is in hand.
     */
    public function viewAny(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function view(User $user, EmployeeEndorsement $endorsement): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Submitting one — the API endpoint Core 1 posts to.
     *
     * Core 1 authenticates with a Sanctum token like every other API caller,
     * and that token belongs to a login on this system: issue it from an HR or
     * admin account and this passes. It is not left open to any authenticated
     * token on the reasoning that "submitting creates nothing" — true, but an
     * employee's `supervisor` could then queue hires, and a queue anybody can
     * fill is one people stop reading.
     */
    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Accepting or declining one.
     *
     * Separate from `view` because reading the queue and answering it are
     * different acts, even where the answer is the same today — a reviewer
     * who may look but not decide is a role this system might grow, and
     * having the question already asked is what makes that a config change
     * rather than a rewrite.
     */
    public function decide(User $user, EmployeeEndorsement $endorsement): bool
    {
        // A decision already taken is history. Re-deciding would either
        // create a second employee from one endorsement or quietly overwrite
        // who was recorded as having approved the first.
        return $user->isHrAdmin() && $endorsement->isPending();
    }
}
