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
 * Module 1 master data — positions.
 *
 * A job title inside a department, carrying the salary band Salaries &
 * Adjustments checks a new rate against. The band is advisory there, never
 * enforced — so a position with no band is not an error, but it is worth
 * seeing, because a rate keyed against it has nothing to be compared to.
 */
class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manageOrganization', Setting::class);

        $filters = [
            'search' => $request->string('search')->trim()->value(),
            'department_id' => $request->integer('department_id') ?: null,
        ];

        $positions = Position::with([
            'department:id,name',

            /*
             * The people holding each title, so the screen can be walked into
             * rather than read as a table of counts.
             *
             * Eager-loaded in one query rather than fetched per card: fifteen
             * positions opened one at a time would be fifteen round trips for
             * a set this size, and the browser is holding the answer either
             * way once the page has rendered.
             *
             * Active only. A position's headcount is who holds it now — a
             * separated employee is a record, and the archive is where that
             * question is asked.
             */
            'employees' => fn ($query) => $query
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->select([
                    'id', 'employee_number', 'first_name', 'middle_name', 'last_name',
                    'suffix', 'photo_path', 'position_id', 'department_id',
                ]),
        ])
            ->withCount(['employees' => fn ($query) => $query->where('status', 'active')])
            ->search($filters['search'])
            ->when(
                $filters['department_id'],
                fn ($query, $value) => $query->where('department_id', $value),
            )
            ->orderBy('title')
            ->get();

        return Inertia::render('HR/MasterData/Positions', [
            'positions' => $positions->map(fn (Position $position) => [
                'id' => $position->id,
                'code' => $position->code,
                'title' => $position->title,
                'department_id' => $position->department_id,
                'department' => $position->department?->name,
                'salary_grade' => $position->salary_grade,
                'min_salary' => $position->min_salary ? (float) $position->min_salary : null,
                'max_salary' => $position->max_salary ? (float) $position->max_salary : null,
                'is_active' => $position->is_active,
                'employees_count' => $position->employees_count,

                /*
                 * Deliberately a name, a number, and a photo — nothing else.
                 *
                 * This screen is master data behind `manageOrganization`, not
                 * a record screen: it answers "who holds this title", which
                 * needs no salary, no government number, and no 201 file. The
                 * same narrowing `DirectoryController::card()` makes, for the
                 * same reason — the field list is what keeps a widened screen
                 * safe.
                 */
                'employees' => $position->employees->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'photo_url' => $employee->photo_path
                        ? asset('storage/'.$employee->photo_path)
                        : null,
                ]),
            ]),

            /*
             * Where somebody may be moved to. Active only, and the same rule
             * `EndorsementService::suggestions()` applies: a deactivated
             * position is kept so history keeps what it was filed under, not
             * so a new person can be filed against it.
             */
            'moveTargets' => Position::with('department:id,name')
                ->withCount(['employees' => fn ($query) => $query->where('status', 'active')])
                ->where('is_active', true)
                ->orderBy('title')
                ->get()
                ->map(fn (Position $position) => [
                    'value' => $position->id,
                    'title' => $position->title,
                    'code' => $position->code,
                    'department' => $position->department?->name,
                    'department_id' => $position->department_id,
                    'salary_grade' => $position->salary_grade,
                    /*
                     * The band comes along because the question being asked in
                     * the picker is "where does this person go", and a title's
                     * band is part of that answer. It is not this screen's job
                     * to compare it against what they earn — that is Salaries &
                     * Adjustments, and putting a rate here would put salary on
                     * a screen that has never carried it.
                     */
                    'min_salary' => $position->min_salary ? (float) $position->min_salary : null,
                    'max_salary' => $position->max_salary ? (float) $position->max_salary : null,
                    'employees_count' => $position->employees_count,
                ]),
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $department) => [
                    'value' => $department->id,
                    'label' => $department->name,
                ]),
            // Whole-table counts, so the summary does not move when filtering.
            'summary' => [
                'total' => Position::count(),
                'active' => Position::where('is_active', true)->count(),
                'without_band' => Position::whereNull('min_salary')
                    ->orWhereNull('max_salary')
                    ->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        Position::create($this->rules($request));

        return back()->with('success', 'Position created.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        $position->update($this->rules($request, $position));

        return back()->with('success', 'Position updated.');
    }

    public function destroy(Position $position): RedirectResponse
    {
        Gate::authorize('manageOrganization', Setting::class);

        // Employees point here; deactivate so their history keeps its title.
        if ($position->employees()->exists()) {
            $position->update(['is_active' => false]);

            return back()->with('success', 'Position is in use — deactivated instead of deleted.');
        }

        $position->delete();

        return back()->with('success', 'Position deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?Position $position = null): array
    {
        $validated = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'code' => [
                'required', 'string', 'max:24',
                Rule::unique('positions', 'code')->ignore($position?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'salary_grade' => ['nullable', 'string', 'max:16'],
            'min_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'gte:min_salary'],
            'is_active' => ['boolean'],
        ], [
            'max_salary.gte' => 'The maximum salary cannot be below the minimum.',
        ]);

        return [
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
