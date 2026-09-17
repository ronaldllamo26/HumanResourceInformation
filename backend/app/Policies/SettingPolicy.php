<?php

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    /**
     * Company-wide configuration is an administrator's job. HR staff run the
     * modules; they do not reconfigure the application.
     */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Org structure is HR's to maintain, not just the administrator's. */
    public function manageOrganization(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /** Creating and deactivating logins is an administrator action. */
    public function manageUsers(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Everyone reaches their own appearance and security preferences. */
    public function managePersonal(User $user): bool
    {
        return true;
    }

    /**
     * Renaming *yourself* — separate from `managePersonal`, which is about
     * reaching the screen at all.
     *
     * Everyone but an administrator is held to the name on their employee
     * record. `users.name` and `employees` are meant to name the same person
     * and **nothing reconciles them** — no checker compares the two, and
     * `RecordIntegrityChecker` does not either: its name check is about a
     * scanned document naming the wrong employee, not about a login. So a
     * drift here is silent and stays that way, which is the reason to prevent
     * it rather than to detect it. HR maintains the employee record; the login
     * name follows from it.
     *
     * An admin keeps it because an admin need not be an employee at all: a
     * pure system account has no 201 file to be held to, and locking it would
     * leave a wrong name with nowhere to be fixed.
     *
     * Email is deliberately *not* covered. It is a credential rather than a
     * display name — it is what you sign in with and where a reset is sent —
     * and changing one already forces re-verification. Password sits beside
     * it for the same reason.
     */
    public function renameSelf(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewAuditLog(User $user): bool
    {
        return $user->isHrAdmin();
    }

    /**
     * Building and downloading a report.
     *
     * HR and admin only, with no supervisor exemption. A report is many
     * people's records in one file that then lives in somebody's downloads
     * folder — `EmployeePolicy::view` narrows a supervisor to their own team
     * for exactly that class of data, and a report cannot be narrowed that way
     * without becoming a different report. A supervisor who needs their team's
     * attendance reads it on the Daily Time Records screen, which is scoped.
     */
    public function viewReports(User $user): bool
    {
        return $user->isHrAdmin();
    }
}
