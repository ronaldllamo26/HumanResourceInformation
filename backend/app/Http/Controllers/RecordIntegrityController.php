<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeService;
use App\Services\RecordIntegrityChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI & Analytics — where the records disagree with each other.
 *
 * Sits beside Credentials and 201 File Status for the same reason they do: it
 * reads across a module rather than maintaining one, and owns no table.
 *
 * Scoped through EmployeeService::scopedQuery(), so a supervisor sees the
 * findings on their own reports and nobody else's — the same narrowing the
 * directory uses. The duplicate-number check is the one exception and it is
 * deliberate: a collision is looked for across every record, because a number
 * shared with somebody outside this user's scope is still shared.
 */
class RecordIntegrityController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly RecordIntegrityChecker $checker,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $findings = $this->checker->scan($this->employees->scopedQuery($request->user()));

        return Inertia::render('HR/Analytics/RecordChecks', [
            'rows' => $findings->values()->all(),
            'summary' => [
                'employees' => $this->employees->scopedQuery($request->user())->count(),
                'affected' => $findings->count(),
                'errors' => $findings->where('severity', 'error')->count(),
                'warnings' => $findings->where('severity', 'warning')->count(),
                // Counted across findings rather than employees: one person
                // can carry several, and "how much is there to fix" is a
                // different question from "how many people are affected".
                'findings' => $findings->sum(fn (array $row) => count($row['findings'])),
            ],
            // What is checked, described from the config that does the
            // checking — a screen that advertises a rule it does not run is
            // worse than one that says nothing.
            'checks' => [
                'formats' => collect(config('integrity.number_formats'))
                    ->map(fn (array $rule) => ['label' => $rule['label'], 'shape' => $rule['shape']])
                    ->values()
                    ->all(),
                'singleCopy' => array_map(
                    fn (string $type) => config("scanner.labels.{$type}", $type),
                    config('integrity.single_copy_types', []),
                ),
            ],
        ]);
    }
}
