<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeQualificationRequest;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\EmployeeSkill;
use App\Models\EmployeeTraining;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Module 1 — the educational and qualification half of the 201 file.
 *
 * Thin on purpose: there is no service here because there is no business rule
 * to hold. A school and a training course are recorded facts, not derived
 * ones — nothing computes off them, nothing else has to agree with them, and
 * a service that only forwarded to `create()` would be a layer pretending to
 * earn its place. The rules that do exist (which levels, which grades, what a
 * plausible year is) live in `config/qualifications.php` and are enforced by
 * StoreEmployeeQualificationRequest.
 *
 * Every write is gated on `EmployeePolicy::update`, not on a permission of its
 * own: editing somebody's qualifications is editing their record.
 */
class EmployeeQualificationController extends Controller
{
    public function storeEducation(StoreEmployeeQualificationRequest $request, Employee $employee): RedirectResponse
    {
        $employee->educations()->create($request->validated());

        return back()->with('success', 'Education record added.');
    }

    public function updateEducation(
        StoreEmployeeQualificationRequest $request,
        Employee $employee,
        EmployeeEducation $education,
    ): RedirectResponse {
        $this->belongsTo($employee, $education->employee_id);

        $education->update($request->validated());

        return back()->with('success', 'Education record updated.');
    }

    public function destroyEducation(Employee $employee, EmployeeEducation $education): RedirectResponse
    {
        Gate::authorize('update', $employee);
        $this->belongsTo($employee, $education->employee_id);

        $education->delete();

        return back()->with('success', 'Education record removed.');
    }

    public function storeTraining(StoreEmployeeQualificationRequest $request, Employee $employee): RedirectResponse
    {
        $employee->trainings()->create($request->validated());

        return back()->with('success', 'Training record added.');
    }

    public function updateTraining(
        StoreEmployeeQualificationRequest $request,
        Employee $employee,
        EmployeeTraining $training,
    ): RedirectResponse {
        $this->belongsTo($employee, $training->employee_id);

        $training->update($request->validated());

        return back()->with('success', 'Training record updated.');
    }

    public function destroyTraining(Employee $employee, EmployeeTraining $training): RedirectResponse
    {
        Gate::authorize('update', $employee);
        $this->belongsTo($employee, $training->employee_id);

        $training->delete();

        return back()->with('success', 'Training record removed.');
    }

    public function storeSkill(StoreEmployeeQualificationRequest $request, Employee $employee): RedirectResponse
    {
        $employee->skills()->create($request->validated());

        return back()->with('success', 'Skill added.');
    }

    public function updateSkill(
        StoreEmployeeQualificationRequest $request,
        Employee $employee,
        EmployeeSkill $skill,
    ): RedirectResponse {
        $this->belongsTo($employee, $skill->employee_id);

        $skill->update($request->validated());

        return back()->with('success', 'Skill updated.');
    }

    public function destroySkill(Employee $employee, EmployeeSkill $skill): RedirectResponse
    {
        Gate::authorize('update', $employee);
        $this->belongsTo($employee, $skill->employee_id);

        $skill->delete();

        return back()->with('success', 'Skill removed.');
    }

    /**
     * Refuses a child that belongs to somebody else.
     *
     * Both ids are in the URL and Laravel binds them independently, so
     * `/employees/7/skills/12` resolves happily when skill 12 is employee 9's
     * — and the policy check above passed on employee 7. Without this, being
     * allowed to edit one person is being allowed to delete a row off anyone.
     */
    private function belongsTo(Employee $employee, int $ownerId): void
    {
        abort_unless($ownerId === $employee->id, 404);
    }
}
