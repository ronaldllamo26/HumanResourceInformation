<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\EmployeeDocument;
use App\Services\CredentialExpiryScanner;
use App\Services\EmployeeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 1 — which 201-file documents are about to lapse.
 *
 * Scoped the same way as the employee directory: HR sees everyone, a
 * supervisor sees their reports, and an employee sees their own — so this
 * doubles as self-service ("your licence expires in three weeks") without a
 * second screen.
 */
class CredentialController extends Controller
{
    public function __construct(
        private readonly CredentialExpiryScanner $scanner,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $request->query('status'),
            'type' => $request->query('type'),
            'department_id' => $request->query('department_id'),

            /*
             * Set by the "Stops Work" tile, and not a status.
             *
             * A document blocks because of its *type* — a licence, a medical —
             * and it can be either already expired or merely expiring, so the
             * count crosses the status filter rather than sitting inside it.
             * Without a key of its own the tile could not open the rows it
             * counted, which is the only thing that makes a figure worth
             * clicking.
             */
            'blocking' => $request->query('blocking'),
        ];

        $credentials = $this->scanner->scan($this->scopedQuery($request));

        // Counted before the filters narrow it, so the summary always describes
        // the whole picture rather than the current view of it.
        $summary = [
            'total' => $credentials->count(),
            'expired' => $credentials->where('status', CredentialExpiryScanner::STATUS_EXPIRED)->count(),
            'expiring' => $credentials->where('status', CredentialExpiryScanner::STATUS_EXPIRING)->count(),
            'blocking' => $credentials->where('blocking', true)->count(),
        ];

        $filtered = $credentials
            ->when($filters['status'], fn ($rows, $value) => $rows->where('status', $value))
            ->when($filters['type'], fn ($rows, $value) => $rows->where('type', $value))
            ->when(
                filter_var($filters['blocking'], FILTER_VALIDATE_BOOLEAN),
                fn ($rows) => $rows->where('blocking', true),
            )
            ->when(
                $filters['department_id'],
                fn ($rows, $value) => $rows->where(
                    'department',
                    Department::find($value)?->name,
                ),
            );

        return Inertia::render('HR/Employees/Credentials', [
            'credentials' => $filtered->values(),
            'summary' => $summary,
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'types' => collect(EmployeeDocument::TYPES)
                ->map(fn (string $type) => [
                    'value' => $type,
                    'label' => ucfirst(str_replace('_', ' ', $type)),
                ])
                ->values(),
            'statuses' => [
                ['value' => CredentialExpiryScanner::STATUS_EXPIRED, 'label' => 'Expired'],
                ['value' => CredentialExpiryScanner::STATUS_EXPIRING, 'label' => 'Expiring soon'],
            ],
        ]);
    }

    /** Documents belonging to employees this user is allowed to see. */
    private function scopedQuery(Request $request)
    {
        return EmployeeDocument::query()->whereIn(
            'employee_id',
            $this->employees->scopedQuery($request->user())->select('employees.id'),
        );
    }
}
