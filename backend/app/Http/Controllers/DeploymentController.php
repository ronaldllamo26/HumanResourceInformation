<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\CredentialExpiryScanner;
use App\Services\DeploymentReadinessChecker;
use App\Services\EmployeeService;
use App\Services\OnboardingChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unified monitor for workforce readiness — combines deployment bench status,
 * 201-file completeness, and credential validity into one consolidated screen.
 */
class DeploymentController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly DeploymentReadinessChecker $readiness,
        private readonly CredentialExpiryScanner $credentialScanner,
        private readonly OnboardingChecker $onboardingChecker,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Employee::class);

        $scoped = $this->employees->scopedQuery($request->user());

        // 1. Deployment Readiness Data
        $deploymentRows = $this->readiness->scan(clone $scoped);

        $filters = [
            'status' => in_array($request->query('status'), [
                DeploymentReadinessChecker::STATUS_READY,
                DeploymentReadinessChecker::STATUS_WARNING,
                DeploymentReadinessChecker::STATUS_BLOCKED,
            ], true) ? $request->query('status') : null,
            'client_id' => $request->integer('client_id') ?: null,
            'search' => $request->string('search')->trim()->value() ?: null,
            'tab' => in_array($request->query('tab'), ['deployment', 'onboarding', 'credentials'], true)
                ? $request->query('tab')
                : 'deployment',
            'department_id' => $request->query('department_id'),
            'cred_status' => $request->query('cred_status'),
            'cred_type' => $request->query('cred_type'),
            'blocking' => $request->query('blocking'),
        ];

        $filteredDeployment = $deploymentRows
            ->when($filters['status'], fn ($rows, $status) => $rows->where('status', $status))
            ->when($filters['client_id'], fn ($rows, $id) => $rows->where('client_id', $id))
            ->when($filters['search'], fn ($rows, $term) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['employee_name']), mb_strtolower($term))
                    || str_contains(mb_strtolower($row['employee_number']), mb_strtolower($term)),
            ))
            ->values();

        $deploymentSummary = [
            'ready' => $deploymentRows->where('status', DeploymentReadinessChecker::STATUS_READY)->count(),
            'warning' => $deploymentRows->where('status', DeploymentReadinessChecker::STATUS_WARNING)->count(),
            'blocked' => $deploymentRows->where('status', DeploymentReadinessChecker::STATUS_BLOCKED)->count(),
            'total' => $deploymentRows->count(),
        ];

        // 2. Credentials Data
        $docQuery = EmployeeDocument::query()->whereIn(
            'employee_id',
            (clone $scoped)->select('employees.id'),
        );
        $allCredentials = $this->credentialScanner->scan($docQuery);

        $credentialsSummary = [
            'total' => $allCredentials->count(),
            'expired' => $allCredentials->where('status', CredentialExpiryScanner::STATUS_EXPIRED)->count(),
            'expiring' => $allCredentials->where('status', CredentialExpiryScanner::STATUS_EXPIRING)->count(),
            'blocking' => $allCredentials->where('blocking', true)->count(),
        ];

        $deptName = $filters['department_id'] ? Department::find($filters['department_id'])?->name : null;

        $filteredCredentials = $allCredentials
            ->when($filters['cred_status'], fn ($rows, $val) => $rows->where('status', $val))
            ->when($filters['cred_type'], fn ($rows, $val) => $rows->where('type', $val))
            ->when(
                filter_var($filters['blocking'], FILTER_VALIDATE_BOOLEAN),
                fn ($rows) => $rows->where('blocking', true),
            )
            ->when($deptName, fn ($rows, $name) => $rows->where('department', $name))
            ->when($filters['search'], fn ($rows, $term) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['employee_name'] ?? ''), mb_strtolower($term))
                    || str_contains(mb_strtolower($row['employee_number'] ?? ''), mb_strtolower($term)),
            ))
            ->values();

        // 3. 201 File Status (Onboarding) Data
        $allOnboarding = $this->onboardingChecker->scan(clone $scoped);

        $onboardingSummary = [
            'incomplete' => $allOnboarding->count(),
            'blocking' => $allOnboarding->where('blocking', '>', 0)->count(),
            'missing_documents' => $allOnboarding->sum(
                fn (array $row) => collect($row['missing'])->where('kind', 'document')->count(),
            ),
            'missing_numbers' => $allOnboarding->sum(
                fn (array $row) => collect($row['missing'])->where('kind', 'government_number')->count(),
            ),
        ];

        $filteredOnboarding = $allOnboarding
            ->when($deptName, fn ($items, $name) => $items->where('department', $name))
            ->when(
                filter_var($filters['blocking'], FILTER_VALIDATE_BOOLEAN),
                fn ($items) => $items->where('blocking', '>', 0),
            )
            ->when($filters['search'], fn ($items, $term) => $items->filter(
                fn (array $row) => str_contains(mb_strtolower($row['employee_name'] ?? ''), mb_strtolower($term))
                    || str_contains(mb_strtolower($row['employee_number'] ?? ''), mb_strtolower($term)),
            ))
            ->values();

        return Inertia::render('HR/Deployment', [
            // Core Deployment Props (matches existing DeploymentReadinessTest expectations)
            'rows' => $filteredDeployment,
            'filters' => $filters,
            'clients' => Client::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'summary' => $deploymentSummary,

            // Unified Credentials Props
            'credentials' => $filteredCredentials,
            'credentialsSummary' => $credentialsSummary,
            'credentialTypes' => collect(EmployeeDocument::TYPES)
                ->map(fn (string $type) => [
                    'value' => $type,
                    'label' => ucfirst(str_replace('_', ' ', $type)),
                ])
                ->values(),

            // Unified Onboarding (201 File Status) Props
            'onboardingRows' => $filteredOnboarding,
            'onboardingSummary' => $onboardingSummary,
            'departments' => Department::orderBy('name')->get(['id', 'name']),

            // Current active tab indicator
            'activeTab' => $filters['tab'],
        ]);
    }
}

