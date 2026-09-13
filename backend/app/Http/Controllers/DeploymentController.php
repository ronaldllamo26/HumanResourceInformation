<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Services\DeploymentReadinessChecker;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 — who can be sent to a client, and who cannot.
 *
 * The one screen in the system that exists to answer a question no single
 * module can: deployability depends on credentials, on 201-file completeness,
 * and on employment standing at once. HR used to hold that answer in their
 * head across four screens, which works until the day somebody is in a hurry.
 *
 * Scoped through `EmployeeService::scopedQuery()` like every other list, so a
 * supervisor sees their own reports and nobody else's.
 */
class DeploymentController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DeploymentReadinessChecker $readiness,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $rows = $this->readiness->scan(
            $this->employees->scopedQuery($request->user()),
        );

        $filters = [
            'status' => in_array($request->query('status'), [
                DeploymentReadinessChecker::STATUS_READY,
                DeploymentReadinessChecker::STATUS_WARNING,
                DeploymentReadinessChecker::STATUS_BLOCKED,
            ], true) ? $request->query('status') : null,
            'client_id' => $request->integer('client_id') ?: null,
            'search' => $request->string('search')->trim()->value() ?: null,
        ];

        $filtered = $rows
            ->when($filters['status'], fn ($rows, $status) => $rows->where('status', $status))
            ->when($filters['client_id'], fn ($rows, $id) => $rows->where('client_id', $id))
            ->when($filters['search'], fn ($rows, $term) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['employee_name']), mb_strtolower($term))
                    || str_contains(mb_strtolower($row['employee_number']), mb_strtolower($term)),
            ))
            ->values();

        return Inertia::render('HR/Deployment', [
            'rows' => $filtered,
            'filters' => $filters,
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Counted before filtering, so the tiles describe the whole bench
            // rather than the current view of it.
            'summary' => [
                'ready' => $rows->where('status', DeploymentReadinessChecker::STATUS_READY)->count(),
                'warning' => $rows->where('status', DeploymentReadinessChecker::STATUS_WARNING)->count(),
                'blocked' => $rows->where('status', DeploymentReadinessChecker::STATUS_BLOCKED)->count(),
                'total' => $rows->count(),
            ],
        ]);
    }
}
