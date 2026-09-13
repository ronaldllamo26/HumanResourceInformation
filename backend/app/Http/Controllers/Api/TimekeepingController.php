<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceLogRequest;
use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\TimekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * REST API for Module 2. Shares TimekeepingService with the Inertia controller.
 * The store endpoint doubles as the biometric import target.
 */
class TimekeepingController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    /** GET /api/v1/attendance */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $perPage = min((int) $request->query('per_page', 20), 100);

        $logs = $this->timekeeping
            ->scopedQuery($request->user())
            ->filter($request->only(['from', 'to', 'employee_id', 'department_id', 'status']))
            ->orderByDesc('log_date')
            ->paginate($perPage)
            ->withQueryString();

        return AttendanceLogResource::collection($logs);
    }

    /** POST /api/v1/attendance — upserts the row for the employee/date. */
    public function store(StoreAttendanceLogRequest $request): JsonResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $log = $this->timekeeping->record($employee, $request->validated());

        return (new AttendanceLogResource($log->load(['employee', 'shift'])))
            ->response()
            ->setStatusCode($log->wasRecentlyCreated ? 201 : 200);
    }

    /** GET /api/v1/attendance/{attendanceLog} */
    public function show(AttendanceLog $attendanceLog): AttendanceLogResource
    {
        Gate::authorize('view', $attendanceLog);

        return new AttendanceLogResource($attendanceLog->load(['employee', 'shift']));
    }

    /** DELETE /api/v1/attendance/{attendanceLog} */
    public function destroy(AttendanceLog $attendanceLog): JsonResponse
    {
        Gate::authorize('delete', $attendanceLog);

        $attendanceLog->delete();

        return response()->json(['message' => 'Time record deleted.']);
    }

    /** GET /api/v1/attendance/summary */
    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $query = $this->timekeeping
            ->scopedQuery($request->user())
            ->filter($request->only(['from', 'to', 'employee_id', 'department_id', 'status']));

        return response()->json(['data' => $this->timekeeping->summary($query)]);
    }
}
