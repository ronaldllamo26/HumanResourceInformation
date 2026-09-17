<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 master data — departments.
 *
 * The org unit every other module reads: employee records, KPI scoping, and
 * payroll reporting all group by it. Kept beside Employee Information rather
 * than under Settings, because HR maintains it while filing people, not while
 * configuring the system.
 *
 * Reuses the `manageOrganization` gate rather than a new permission — the
 * people allowed to shape the org chart have not changed by moving the screen.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageOrganization', Setting::class);

        $search = $request->string('search')->trim()->value();

        $departments = Department::withCount(['employees', 'positions'])
            ->with([
                'positions' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with([
                        'employees' => fn ($eq) => $eq
                            ->where('status', 'active')
                            ->orderBy('last_name')
                            ->orderBy('first_name')
                            ->select([
                                'id', 'employee_number', 'first_name', 'middle_name', 'last_name',
                                'suffix', 'photo_path', 'position_id', 'department_id',
                            ]),
                    ])
                    ->withCount(['employees' => fn ($eq) => $eq->where('status', 'active')])
                    ->orderBy('title'),
                'employees' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereNull('position_id')
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->select([
                        'id', 'employee_number', 'first_name', 'middle_name', 'last_name',
                        'suffix', 'photo_path', 'position_id', 'department_id',
                    ]),
            ])
            ->search($search)
            ->orderBy('name')
            ->get();

        return Inertia::render('HR/MasterData/Departments', [
            'departments' => $departments->map(fn (Department $department) => [
                'id' => $department->id,
                'code' => $department->code,
                'name' => $department->name,
                'description' => $department->description,
                'is_active' => $department->is_active,
                'employees_count' => $department->employees_count,
                'positions_count' => $department->positions_count,
                'positions' => $department->positions->map(fn ($pos) => [
                    'id' => $pos->id,
                    'code' => $pos->code,
                    'title' => $pos->title,
                    'salary_grade' => $pos->salary_grade,
                    'min_salary' => $pos->min_salary ? (float) $pos->min_salary : null,
                    'max_salary' => $pos->max_salary ? (float) $pos->max_salary : null,
                    'employees_count' => $pos->employees_count,
                    'employees' => $pos->employees->map(fn ($emp) => [
                        'id' => $emp->id,
                        'employee_number' => $emp->employee_number,
                        'full_name' => $emp->full_name,
                        'photo_url' => $emp->photo_path ? asset('storage/'.$emp->photo_path) : null,
                    ]),
                ]),
                'unassigned_employees' => $department->employees->map(fn ($emp) => [
                    'id' => $emp->id,
                    'employee_number' => $emp->employee_number,
                    'full_name' => $emp->full_name,
                    'photo_url' => $emp->photo_path ? asset('storage/'.$emp->photo_path) : null,
                ]),
            ]),
            'filters' => ['search' => $search],
            // Counted across the whole table, not the filtered page — a
            // summary that moves when you type is not a summary.
            'summary' => [
                'total' => Department::count(),
                'active' => Department::where('is_active', true)->count(),
                'empty' => Department::whereDoesntHave('employees')->count(),
                'total_positions' => Position::count(),
                'total_employees' => Employee::where('status', 'active')->count(),
            ],
            'departmentOptions' => Department::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($d) => ['value' => $d->id, 'label' => $d->name]),
            'moveTargets' => Position::with('department:id,name')
                ->withCount(['employees' => fn ($q) => $q->where('status', 'active')])
                ->where('is_active', true)
                ->orderBy('title')
                ->get()
                ->map(fn ($pos) => [
                    'value' => $pos->id,
                    'title' => $pos->title,
                    'code' => $pos->code,
                    'department' => $pos->department?->name,
                    'department_id' => $pos->department_id,
                    'salary_grade' => $pos->salary_grade,
                    'min_salary' => $pos->min_salary ? (float) $pos->min_salary : null,
                    'max_salary' => $pos->max_salary ? (float) $pos->max_salary : null,
                    'employees_count' => $pos->employees_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Department::create($this->rules($request));

        return back()->with('success', 'Department created.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $department->update($this->rules($request, $department));

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        // Employees and positions point here; deactivate so history survives.
        if ($department->employees()->exists() || $department->positions()->exists()) {
            $department->update(['is_active' => false]);

            return back()->with('success', 'Department is in use — deactivated instead of deleted.');
        }

        $department->delete();

        return back()->with('success', 'Department deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Department $department = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:24',
                Rule::unique('departments', 'code')->ignore($department?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
