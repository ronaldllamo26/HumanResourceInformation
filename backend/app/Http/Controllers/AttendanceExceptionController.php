<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Services\AttendanceExceptionScanner;
use App\Services\TimekeepingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — the automated DTR checker.
 *
 * Scans the filtered range for records worth a second look before payroll
 * pays them: missing punches, unusually late or long days, and employees
 * trending toward chronic lateness or absence.
 */
class AttendanceExceptionController extends Controller
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly AttendanceExceptionScanner $scanner,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $filters = [
            'from' => $request->query('from', Carbon::now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', Carbon::now()->endOfMonth()->toDateString()),
            'department_id' => $request->query('department_id'),
            'type' => $request->query('type'),
        ];

        $query = $this->timekeeping->scopedQuery($request->user())->filter($filters);
        $exceptions = $this->scanner->scan($query);

        if ($filters['type']) {
            $exceptions = $exceptions->where('type', $filters['type']);
        }

        return Inertia::render('HR/Timekeeping/Exceptions', [
            'exceptions' => $exceptions->values(),
            'summary' => [
                'total' => $exceptions->count(),
                'critical' => $exceptions->where('severity', 'critical')->count(),
                'warning' => $exceptions->where('severity', 'warning')->count(),
                'employees_flagged' => $exceptions->pluck('employee_id')->unique()->count(),
            ],
            'filters' => $filters,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'types' => [
                ['value' => AttendanceExceptionScanner::TYPE_MISSING_PUNCH, 'label' => 'Missing Time-Out'],
                ['value' => AttendanceExceptionScanner::TYPE_EXCESSIVE_LATE, 'label' => 'Excessive Lateness'],
                ['value' => AttendanceExceptionScanner::TYPE_EXCESSIVE_OVERTIME, 'label' => 'Excessive Overtime'],
                ['value' => AttendanceExceptionScanner::TYPE_FREQUENT_LATE, 'label' => 'Frequent Lateness'],
                ['value' => AttendanceExceptionScanner::TYPE_FREQUENT_ABSENCE, 'label' => 'Frequent Absence'],
            ],
        ]);
    }
}
