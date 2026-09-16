<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\DataAccessLogger;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * REST API for Module 1, protected by Sanctum tokens.
 * Shares EmployeeService with the Inertia controller so both stay in step.
 */
class EmployeeController extends Controller
{
    private const SORTABLE = [
        'employee_number', 'last_name', 'first_name',
        'date_hired', 'employment_status', 'status',
    ];

    public function __construct(private readonly EmployeeService $employees) {}

    /** GET /api/v1/employees */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Employee::class);

        $sort = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort')
            : 'last_name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = min((int) $request->query('per_page', 15), 100);

        $employees = $this->employees
            ->scopedQuery($request->user())
            ->filter($request->only(['search', 'department_id', 'employment_status', 'status']))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /** POST /api/v1/employees */
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create($request->validated(), $request->file('photo'));

        return (new EmployeeResource($employee->load(['department', 'position'])))
            ->additional(array_filter([
                // Only present when a login was created. The username is what
                // the person signs in with, so it travels with the password.
                'username' => $this->employees->generatedPassword ? $employee->user?->username : null,
                'temporary_password' => $this->employees->generatedPassword,
            ]))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/v1/employees/{employee} */
    public function show(Employee $employee, DataAccessLogger $access): EmployeeResource
    {
        Gate::authorize('view', $employee);

        $access->accessed($employee, 'api_view', ['employee' => $employee->full_name]);

        return new EmployeeResource(
            $employee->load(['department', 'position', 'supervisor', 'documents.uploader']),
        );
    }

    /** PUT/PATCH /api/v1/employees/{employee} */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $employee = $this->employees->update($employee, $request->validated(), $request->file('photo'));

        return new EmployeeResource($employee->load(['department', 'position']));
    }

    /** DELETE /api/v1/employees/{employee} */
    public function destroy(Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);

        $this->employees->delete($employee);

        return response()->json([
            'message' => "Employee {$employee->employee_number} archived.",
        ]);
    }

    /** POST /api/v1/employees/{employee}/restore */
    public function restore(int $employeeId): EmployeeResource
    {
        $employee = Employee::onlyTrashed()->findOrFail($employeeId);

        Gate::authorize('restore', $employee);

        $this->employees->restore($employee);

        return new EmployeeResource($employee->refresh());
    }

    /** GET /api/v1/employees/statistics */
    public function statistics(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        return response()->json([
            'data' => $this->employees->statistics(
                $this->employees->scopedQuery($request->user()),
            ),
        ]);
    }

    /** GET /api/v1/employees/{employee}/documents */
    public function documents(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('view', $employee);

        return EmployeeDocumentResource::collection(
            $employee->documents()->with('uploader:id,name')->latest()->get(),
        );
    }

    /** POST /api/v1/employees/{employee}/documents */
    public function storeDocument(StoreEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $document = $this->employees->storeDocument(
            $employee,
            $request->validated(),
            $request->file('file'),
        );

        return (new EmployeeDocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    /** DELETE /api/v1/employees/{employee}/documents/{document} */
    public function destroyDocument(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('manageDocuments', $employee);

        abort_if($document->employee_id !== $employee->id, 404);

        $this->employees->deleteDocument($document);

        return response()->json(['message' => 'Document deleted.']);
    }
}
