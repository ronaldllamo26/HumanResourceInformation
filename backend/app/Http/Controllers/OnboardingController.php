<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\EmployeeService;
use App\Services\OnboardingChecker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 — which 201 files are still incomplete.
 *
 * Scoped like the employee directory, so a supervisor sees their own reports'
 * gaps and can chase them without going through HR.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingChecker $checker,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'department_id' => $request->query('department_id'),
            'blocking' => $request->boolean('blocking'),
        ];

        $rows = $this->checker->scan(
            $this->employees->scopedQuery($request->user()),
        );

        // Counted before filtering, so the tiles describe the whole picture
        // rather than the current view of it.
        $summary = [
            'incomplete' => $rows->count(),
            'blocking' => $rows->where('blocking', '>', 0)->count(),
            'missing_documents' => $rows->sum(
                fn (array $row) => collect($row['missing'])->where('kind', 'document')->count(),
            ),
            'missing_numbers' => $rows->sum(
                fn (array $row) => collect($row['missing'])->where('kind', 'government_number')->count(),
            ),
        ];

        $filtered = $rows
            ->when(
                $filters['department_id'],
                fn ($items, $value) => $items->where('department', Department::find($value)?->name),
            )
            ->when($filters['blocking'], fn ($items) => $items->where('blocking', '>', 0));

        return Inertia::render('HR/Employees/Onboarding', [
            'rows' => $filtered->values(),
            'summary' => $summary,
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
