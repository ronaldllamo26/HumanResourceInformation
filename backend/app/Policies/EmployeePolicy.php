<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    /** Every authenticated role can reach the directory; scoping happens in the query. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->isHrAdmin()) {
            return true;
        }

        // Own 201 file.
        if ($employee->user_id === $user->id) {
            return true;
        }

        // Supervisors see their direct reports.
        return $user->isSupervisor()
            && $employee->supervisor_id !== null
            && $employee->supervisor_id === $user->employee?->id;
    }

    public function create(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin();
    }

    public function delete(User $user, Employee $employee): bool
    {
        // Guard against an admin removing their own record.
        return $user->isAdmin() && $employee->user_id !== $user->id;
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }

    /**
     * The archive screen, which lists deleted employees *and* clients.
     *
     * Separate from `restore` because that one needs a record to judge, and
     * this is asked before any record is in hand. Same answer — putting a
     * deleted record back is an admin act — but it has to be askable of the
     * class rather than of an instance.
     */
    /**
     * The org directory — who works here, and where.
     *
     * **Open to every signed-in user, and that is a deliberate widening of
     * `viewAny`.** A supervisor sees only their reports in the HR directory
     * and an employee sees only themselves, which is right for a screen that
     * carries salary, government numbers, and the 201 file. It is useless for
     * the question this screen answers — "who is in Operations, and how do I
     * reach them" — and a directory nobody can read is not a directory.
     *
     * What makes the widening safe is that the *fields* narrow to match:
     * `DirectoryController` sends a name, a position, a department, a client,
     * and a work contact. No salary, no government numbers, no addresses, no
     * documents. The two halves are one decision — widening the audience
     * without narrowing the fields would be a leak, and narrowing the fields
     * without widening the audience would be pointless.
     *
     * Class-level, like `viewArchive`: it is asked before any record is in
     * hand.
     */
    public function viewDirectory(User $user): bool
    {
        return true;
    }

    public function viewArchive(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Filing a batch of documents, before it is known whose they are.
     *
     * The same relationship `viewArchive` has to `restore`: `manageDocuments`
     * judges one employee's file and needs that employee, and a batch is
     * asked of the class because the scanner has not yet said who the stack
     * belongs to. The answer must stay the same as `manageDocuments` — filing
     * forty documents cannot be open to somebody who may not file one — so it
     * is written as the same expression rather than a different rule that
     * happens to agree today.
     */
    public function fileDocumentBatch(User $user): bool
    {
        return $user->isHrAdmin();
    }

    public function manageDocuments(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin();
    }

    /** Salary, bank details, and government IDs are HR-only. */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $user->isHrAdmin() || $employee->user_id === $user->id;
    }
}
