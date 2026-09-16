<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Time Records — one row per employee per day.
 *
 * Everybody can open it: HR sees everyone, a supervisor the team, an employee
 * their own days. Only HR writes; everyone else files a correction.
 */
class TimeRecordController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeeping) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceLog::class);

        $user = $request->user();
        $from = $this->date($request->input('from')) ?? now()->startOfMonth();
        $to = $this->date($request->input('to')) ?? now()->endOfMonth();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $filters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'status' => in_array($request->input('status'), AttendanceLog::STATUSES, true) ? $request->input('status') : null,
            'employee' => $request->integer('employee') ?: null,
        ];

        $base = $this->timekeeping->scopedLogs($user)
            ->between($filters['from'], $filters['to'])
            ->when($filters['employee'], fn ($query, $id) => $query->where('employee_id', $id));

        $summary = (clone $base)
            ->selectRaw('status, count(*) as days, sum(late_minutes) as late, sum(minutes_worked) as minutes')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $locked = $this->timekeeping->lockedRanges();

        $logs = (clone $base)
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('work_date')
            ->orderBy('employee_id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AttendanceLog $log) => $this->row($log, $locked));

        $worked = collect(AttendanceLog::WORKED_STATUSES)->sum(fn ($status) => (int) ($summary[$status]->days ?? 0));

        return Inertia::render('HR/Timekeeping/Records', [
            'logs' => $logs,
            'filters' => $filters,
            'summary' => [
                'worked' => $worked,
                'late' => (int) ($summary[AttendanceLog::STATUS_LATE]->days ?? 0),
                'absent' => (int) ($summary[AttendanceLog::STATUS_ABSENT]->days ?? 0),
                'incomplete' => (int) ($summary[AttendanceLog::STATUS_INCOMPLETE]->days ?? 0),
                'hours' => round($summary->sum('minutes') / 60, 1),
            ],
            'statuses' => collect(AttendanceLog::STATUSES)->map(fn ($status) => [
                'value' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
            ])->values(),
            'employees' => $this->timekeeping->employeeOptions($user),
            'can' => [
                'manage' => $user->can('manage', AttendanceLog::class),
                'fileCorrection' => $user->employee !== null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $this->validated($request, true);
        $employee = Employee::findOrFail($data['employee_id']);

        $log = $this->timekeeping->record(
            $employee,
            Carbon::parse($data['work_date']),
            $data['time_in'] ?? null,
            $data['time_out'] ?? null,
            AttendanceLog::SOURCE_MANUAL,
            $data['remarks'] ?? null,
            $request->user(),
        );

        return back()->with('success', "Saved {$employee->full_name}'s record for {$log->work_date->format('M j, Y')} ({$this->label($log->status)}).");
    }

    public function update(Request $request, AttendanceLog $record): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $data = $this->validated($request, false);

        $log = $this->timekeeping->record(
            $record->employee,
            $record->work_date,
            $data['time_in'] ?? null,
            $data['time_out'] ?? null,
            AttendanceLog::SOURCE_MANUAL,
            $data['remarks'] ?? null,
            $request->user(),
        );

        return back()->with('success', "Updated the record for {$log->work_date->format('M j, Y')} ({$this->label($log->status)}).");
    }

    public function destroy(AttendanceLog $record): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $this->timekeeping->delete($record);

        return back()->with('success', 'Time record deleted.');
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('manage', AttendanceLog::class);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $result = $this->timekeeping->import($request->file('file'), $request->user());
        $skipped = count($result['skipped']);

        $flash = back()->with('success', "Imported {$result['imported']} day(s)".($skipped ? ", skipped {$skipped}." : '.'));

        // The rows that did not land are the ones somebody has to fix, so name them.
        return $skipped ? $flash->with('error', implode(' ', array_slice($result['skipped'], 0, 5)).($skipped > 5 ? ' …and '.($skipped - 5).' more.' : '')) : $flash;
    }

    /** @return array<string, mixed> */
    private function row(AttendanceLog $log, $locked): array
    {
        return [
            'id' => $log->id,
            'employee' => [
                'id' => $log->employee?->id,
                'name' => $log->employee?->full_name,
                'number' => $log->employee?->employee_number,
            ],
            'work_date' => $log->work_date->toDateString(),
            'day' => $log->work_date->format('D'),
            'shift' => $log->shift?->code,
            'time_in' => $log->time_in?->format('H:i'),
            'time_out' => $log->time_out?->format('H:i'),
            'next_day_out' => $log->time_out && $log->time_out->toDateString() !== $log->work_date->toDateString(),
            'status' => $log->status,
            'hours' => round($log->minutes_worked / 60, 2),
            'late_minutes' => $log->late_minutes,
            'undertime_minutes' => $log->undertime_minutes,
            'overtime_minutes' => $log->overtime_minutes,
            'night_diff_minutes' => $log->night_diff_minutes,
            'source' => $log->source,
            'remarks' => $log->remarks,
            'locked' => $this->timekeeping->isLockedIn($locked, $log->work_date),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'employee_id' => [Rule::requiredIf($creating), 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'work_date' => [Rule::requiredIf($creating), 'date', 'before_or_equal:today'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(string $status): string
    {
        return str_replace('_', ' ', $status);
    }
}
