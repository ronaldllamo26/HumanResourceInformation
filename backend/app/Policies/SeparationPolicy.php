<?php

namespace App\Policies;

use App\Models\Separation;
use App\Models\User;

/**
 * Separation follows payroll's separation of duties: HR staff prepare the
 * settlement, an **admin** releases it. Releasing moves money and closes loan
 * balances, so it is not the same hand that computed it.
 */
class SeparationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function view(User $user, Separation $separation): bool
    {
        return $user->isHrAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** A released settlement is history — nothing about it changes. */
    public function update(User $user, Separation $separation): bool
    {
        return $user->isHrAdmin() && $separation->isEditable();
    }

    /** Only an admin releases, and only once every blocking item is signed off. */
    public function release(User $user, Separation $separation): bool
    {
        return $user->isAdmin()
            && $separation->isEditable()
            && $separation->isCleared();
    }

    public function delete(User $user, Separation $separation): bool
    {
        return $user->isAdmin() && $separation->isEditable();
    }
}
