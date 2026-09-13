<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceLogRequest;
use App\Http\Resources\AttendanceLogResource;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceImporter;
use App\Services\DataAccessLogger;
use App\Services\EmployeeService;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — Timekeeping & Attendance (Inertia entry point).
 */
class TimekeepingController extends Controller
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
    ) {}

    /**
     * Records — how many days each employee came in over a cutoff.
     *
     * This screen used to be a list of individual days, filtered by employee,
     * department, and status. That answered "what happened on this day for
     * this person", which is a question you already have to know the answer to
     * before you can ask it. The question HR actually opens this screen with
     * is "who came in this month, and how many days" — so the table is one row
     * per employee now, and a row opens that person's own screen.
     *
     * The three dropdowns went with the change. Employee and Department picked
     * a subset of a list that is now one line per person; Status narrowed days,
     * which are no longer the rows.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        // Default to the current month so the screen is never empty on arrival.
        $filters = [
            'from' => $request->query('from', Carbon::now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', Carbon::now()->endOfMonth()->toDateString()),
        ];

        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->startOfDay();

        // The tiles count the whole range across everybody in scope; the table
        // pages through the same range one employee at a time.
        $range = $this->timekeeping->scopedQuery($request->user())->filter($filters);

        return Inertia::render('HR/Timekeeping/Index', [
            /*
             * Wrapped rather than handed over raw. A bare paginator puts the
             * numbered page buttons on `links`; a resource collection puts
             * them on `meta.links` and leaves `links` as the
             * {first,last,prev,next} object — which is the shape <Pagination>
             * takes and every other list here sends.
             */
            'rows' => JsonResource::collection(
                $this->timekeeping->attendanceByEmployee($request->user(), $from, $to),
            ),
            'summary' => $this->timekeeping->summary($range),
            'filters' => $filters,
            'shifts' => Shift::where('is_active', true)->orderBy('start_time')->get(['id', 'name', 'start_time', 'end_time']),
            // Still here for the Record Time form, which files one day for one
            // person. It is not a filter any more.
            'employees' => $this->employees->scopedQuery($request->user())
                ->orderBy('last_name')
                ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                ]),
            'statuses' => AttendanceLog::STATUSES,
            'can' => [
                'manage' => $request->user()->can('create', AttendanceLog::class),
            ],
        ]);
    }

    /**
     * One employee's attendance over the cutoff — a calendar and the days
     * behind it.
     *
     * A screen of its own rather than a row that unfolds. The days are a
     * different unit from the summary above them, they want a Monday-to-Sunday
     * grid the list cannot hold, and an accordion inside a paginated table puts
     * one person's fortnight in a strip four columns wide.
     *
     * Gated on EmployeePolicy::view — the record being read is this employee's
     * attendance, so the question is whether the viewer may see *them*, not
     * whether they may see time records in general.
     */
    public function show(Request $request, Employee $employee): Response
    {
        Gate::authorize('view', $employee);

        $filters = [
            'from' => $request->query('from', Carbon::now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', Carbon::now()->endOfMonth()->toDateString()),
        ];

        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->startOfDay();

        $logs = $this->timekeeping->daysFor($request->user(), $employee->id, $from, $to);

        return Inertia::render('HR/Timekeeping/Employee', [
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'department' => $employee->department?->name,
                'client' => $employee->client?->name,
                'position' => $employee->position?->title,
            ],
            'filters' => $filters,
            'weeks' => $this->timekeeping->attendanceCalendar($logs, $from, $to),
            'days' => AttendanceLogResource::collection($logs),
            /*
             * The same aggregate the Records row showed, recomputed here from
             * the same service rather than carried across in the URL — a total
             * passed between two screens is a total that can arrive stale.
             */
            'summary' => $this->timekeeping->summary(
                $this->timekeeping->scopedQuery($request->user())
                    ->where('employee_id', $employee->id)
                    ->filter($filters),
            ),
            'can' => [
                'manage' => $request->user()->can('create', AttendanceLog::class),
            ],
        ]);
    }

    public function store(StoreAttendanceLogRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $log = $this->timekeeping->record($employee, $request->validated());

        return back()->with(
            'success',
            "Time record saved for {$employee->full_name} on {$log->log_date->toFormattedDateString()}.",
        );
    }

    /** Bulk DTR import — the biometric device export lands here. */
    public function import(
        Request $request,
        AttendanceImporter $importer,
        DataAccessLogger $access,
    ): RedirectResponse {
        Gate::authorize('create', AttendanceLog::class);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], [
            'file.mimes' => 'Upload a CSV export.',
        ]);

        $file = $request->file('file');
        $result = $importer->import($file, 'biometric');

        /*
         * The batch, recorded durably.
         *
         * Each row the importer wrote is audited on its own AttendanceLog, but
         * the rows it *refused* were audited nowhere — they were flashed to
         * the session and gone on the next page load. Those are exactly the
         * ones somebody comes looking for a month later, when a fortnight is
         * short and nobody remembers a red box.
         */
        $access->imported('attendance:biometric', AttendanceLog::class, [
            'file' => $file->getClientOriginalName(),
            'imported' => $result['imported'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ]);

        if ($result['imported'] === 0 && $result['failed'] === 0 && $result['errors'] !== []) {
            return back()->with('error', $result['errors'][0]);
        }

        $message = "Imported {$result['imported']} record(s).";

        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} row(s) skipped.";
        }

        return back()
            ->with($result['failed'] > 0 ? 'error' : 'success', $message)
            ->with('importErrors', $result['errors']);
    }

    public function destroy(AttendanceLog $attendanceLog): RedirectResponse
    {
        Gate::authorize('delete', $attendanceLog);

        $attendanceLog->delete();

        return back()->with('success', 'Time record deleted.');
    }
}
