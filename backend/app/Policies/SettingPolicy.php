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

    /**
     * Reviewing, approving, and rejecting account change requests from staff.
     * Both Administrator and Super Administrator have access.
     */
    public function manageAccountRequests(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Viewing plaintext/decrypted staff passwords.
     * Restricted strictly to Super Administrator.
     */
    public function viewStaffPasswords(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Signing in as another account to reproduce what they are seeing.
     *
     * Super administrator only, and not shared with `isAdmin()` the way
     * `manageAccountRequests` is. An administrator can already reset a
     * password and read the audit log, which covers support and accountability
     * between them; impersonation is the one ability that lets somebody *act*
     * as another person, and the narrowest possible holder is the right one
     * for it. Which accounts may be impersonated is a separate question, and
     * `ImpersonationService::refusalReason()` answers it — a super
     * administrator may not be impersonated at all, including by a peer.
     */
    public function impersonate(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Seeing who is signed in, and signing them out.
     *
     * Also super administrator only. Ending somebody's session is a response
     * to a suspected breach rather than an HR task, and the session list is
     * itself sensitive: it is every signed-in person's address and device,
     * which is a map of the workforce's whereabouts that nothing else in this
     * system hands over.
     */
    public function manageSessions(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** Deleting user accounts is strictly Super Administrator only. */
    public function deleteUser(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Taking a copy of the whole database out of the building.
     *
     * Super administrator only, and **not** `manage` — which is what the rest
     * of the Data & Backup screen sits behind. Editing how long the audit
     * trail is kept and downloading every payslip, government identifier and
     * bank account in the company are not the same act, and they happen to be
     * on the same screen only because both are about data.
     *
     * It is a new ability rather than a reuse of `manageSessions` for the
     * reason `viewArchive` is separate from `restore`: these are two different
     * questions and an ability that answers both is one nobody can narrow
     * later. A dump is the most complete export this system can produce — it
     * carries the ciphertext of every encrypted column, which `APP_KEY` is the
     * only thing standing between and plaintext — so it gets the narrowest
     * holder there is.
     */
    public function backupDatabase(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
