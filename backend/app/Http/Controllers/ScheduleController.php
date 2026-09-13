<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeScheduleRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Shift;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — shift definitions and employee schedule assignment.
 * The attendance calculator reads whatever is set here.
 */
class ScheduleController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        $canManage = $request->user()->isHrAdmin();

        $schedules = EmployeeSchedule::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'shift:id,name,start_time,end_time'])
            ->whereIn('employee_id', $this->employees->scopedQuery($request->user())->select('employees.id'))
            ->orderByDesc('effective_from')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Timekeeping/Schedules', [
            'shifts' => Shift::withCount('schedules')
                ->orderBy('start_time')
                ->get()
                ->map(fn (Shift $shift) => [
                    'id' => $shift->id,
                    'name' => $shift->name,
                    'start_time' => substr((string) $shift->start_time, 0, 5),
                    'end_time' => substr((string) $shift->end_time, 0, 5),
                    'break_minutes' => $shift->break_minutes,
                    'grace_period_minutes' => $shift->grace_period_minutes,
                    'is_night_shift' => $shift->is_night_shift,
                    'is_active' => $shift->is_active,
                    'crosses_midnight' => $shift->crossesMidnight(),
                    'assigned_count' => $shift->schedules_count,
                ]),
            'schedules' => [
                'data' => $schedules->map(fn (EmployeeSchedule $schedule) => [
                    'id' => $schedule->id,
                    'employee' => [
                        'id' => $schedule->employee?->id,
                        'full_name' => $schedule->employee?->full_name,
                        'employee_number' => $schedule->employee?->employee_number,
                    ],
                    'shift' => [
                        'id' => $schedule->shift?->id,
                        'name' => $schedule->shift?->name,
                        'start_time' => substr((string) $schedule->shift?->start_time, 0, 5),
                        'end_time' => substr((string) $schedule->shift?->end_time, 0, 5),
                    ],
                    'effective_from' => $schedule->effective_from?->toDateString(),
                    'effective_to' => $schedule->effective_to?->toDateString(),
                    'days_of_week' => $schedule->days_of_week,
                ]),
                'meta' => [
                    'from' => $schedules->firstItem(),
                    'to' => $schedules->lastItem(),
                    'total' => $schedules->total(),
                    'links' => $schedules->linkCollection()->toArray(),
                ],
            ],
            'employees' => $this->employees->scopedQuery($request->user())
                ->orderBy('last_name')
                ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                ]),
            'can' => ['manage' => $canManage],
        ]);
    }

    public function storeShift(StoreShiftRequest $request): RedirectResponse
    {
        Shift::create($request->validated());

        return back()->with('success', 'Shift created.');
    }

    public function updateShift(StoreShiftRequest $request, Shift $shift): RedirectResponse
    {
        $shift->update($request->validated());

        return back()->with('success', 'Shift updated.');
    }

    public function destroyShift(Request $request, Shift $shift): RedirectResponse
    {
        abort_unless($request->user()->isHrAdmin(), 403);

        // Attendance history points at shifts; deactivate rather than orphan it.
        if ($shift->schedules()->exists() || $shift->attendanceLogs()->exists()) {
            $shift->update(['is_active' => false]);

            return back()->with('success', 'Shift is in use — deactivated instead of deleted.');
        }

        $shift->delete();

        return back()->with('success', 'Shift deleted.');
    }

    public function storeSchedule(StoreEmployeeScheduleRequest $request): RedirectResponse
    {
        EmployeeSchedule::create($request->validated());

        return back()->with('success', 'Schedule assigned.');
    }

    public function destroySchedule(Request $request, EmployeeSchedule $schedule): RedirectResponse
    {
        abort_unless($request->user()->isHrAdmin(), 403);

        $schedule->delete();

        return back()->with('success', 'Schedule removed.');
    }
}
