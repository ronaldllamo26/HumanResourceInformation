<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\EmployeeShift;
use App\Models\Shift;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shifts & Rest Days — the working patterns, and who is on which from when.
 *
 * An assignment is history, not a column: moving somebody to nights from
 * October leaves September computed against the day shift they worked.
 */
class ShiftController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manage', AttendanceLog::class);

        $today = now()->toDateString();

        $assignments = EmployeeShift::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'shift:id,code,name,start_time,end_time'])
            ->when($request->boolean('history') === false, fn ($query) => $query
                ->where(fn ($inner) => $inner->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today)))
            ->orderByDesc('effective_from')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EmployeeShift $assignment) => [
                'id' => $assignment->id,
                'employee' => $assignment->employee?->full_name,
                'employee_number' => $assignment->employee?->employee_number,
                'shift' => $assignment->shift ? "{$assignment->shift->code} · ".$this->hours($assignment->shift) : null,
                'rest_days' => collect($assignment->rest_days ?? [])->map(fn ($day) => EmployeeShift::WEEKDAYS[(int) $day] ?? null)->filter()->values(),
                'effective_from' => $assignment->effective_from->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
            ]);

        return Inertia::render('HR/Timekeeping/Shifts', [
            'shifts' => Shift::withCount('assignments')->orderByDesc('is_active')->orderBy('start_time')->get()
                ->map(fn (Shift $shift) => [
                    'id' => $shift->id,
                    'code' => $shift->code,
                    'name' => $shift->name,
                    'start_time' => substr($shift->start_time, 0, 5),
                    'end_time' => substr($shift->end_time, 0, 5),
                    'hours' => $this->hours($shift),
                    'break_minutes' => $shift->break_minutes,
                    'grace_minutes' => $shift->grace_minutes,
                    'is_active' => $shift->is_active,
                    'overnight' => $shift->crossesMidnight(),
                    'assignments_count' => $shift->assignments_count,
                ]),
            'assignments' => $assignments,
            'filters' => ['history' => $request->boolean('history')],
            'employees' => $this->timekeeping->employeeOptions($request->user()),
            'weekdays' => collect(EmployeeShift::WEEKDAYS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $shift = Shift::create($this->validatedShift($request) + ['is_active' => true]);

        return back()->with('success', "Shift {$shift->code} created.");
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $shift->update($this->validatedShift($request, $shift) + [
            'is_active' => $request->boolean('is_active', $shift->is_active),
        ]);

        return back()->with('success', "Shift {$shift->code} updated. Days already recorded keep the figures they were computed with.");
    }

    /** A shift anybody was ever on is deactivated, so history keeps it. */
    public function destroy(Shift $shift): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        if ($shift->assignments()->exists() || AttendanceLog::where('shift_id', $shift->id)->exists()) {
            $shift->update(['is_active' => false]);

            return back()->with('info', "Shift {$shift->code} is in use, so it was deactivated instead of deleted.");
        }

        $shift->delete();

        return back()->with('success', "Shift {$shift->code} deleted.");
    }

    /**
     * Puts an employee on a shift from a date. An open-ended assignment that
     * started earlier is ended the day before, so two can never overlap.
     */
    public function assign(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('is_active', true)],
            'rest_days' => ['array', 'max:6'],
            'rest_days.*' => ['integer', 'between:1,7', 'distinct'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $from = Carbon::parse($data['effective_from'])->startOfDay();

        $overlap = EmployeeShift::query()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('effective_from', '>=', $from->toDateString())
            ->exists();

        if ($overlap) {
            return back()->withErrors([
                'effective_from' => 'This employee already has a schedule starting on or after that date. Remove it first, or start this one later.',
            ]);
        }

        DB::transaction(function () use ($data, $from) {
            EmployeeShift::query()
                ->where('employee_id', $data['employee_id'])
                ->whereDate('effective_from', '<', $from->toDateString())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->get()
                ->each(fn (EmployeeShift $previous) => $previous->update(['effective_to' => $from->copy()->subDay()->toDateString()]));

            EmployeeShift::create([
                'employee_id' => $data['employee_id'],
                'shift_id' => $data['shift_id'],
                'rest_days' => array_values(array_map('intval', $data['rest_days'] ?? [])),
                'effective_from' => $from->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
            ]);
        });

        return back()->with('success', 'Schedule saved. Days recorded from now on are computed against it.');
    }

    public function unassign(EmployeeShift $assignment): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $assignment->delete();

        return back()->with('success', 'Schedule removed.');
    }

    /** @return array<string, mixed> */
    private function validatedShift(Request $request, ?Shift $shift = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('shifts', 'code')->ignore($shift?->id)],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'between:0,180'],
            'grace_minutes' => ['required', 'integer', 'between:0,60'],
        ]);

        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    private function hours(Shift $shift): string
    {
        return substr($shift->start_time, 0, 5).'–'.substr($shift->end_time, 0, 5);
    }
}
