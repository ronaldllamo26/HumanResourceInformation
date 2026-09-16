<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\TimeCorrection;
use App\Services\EmployeeService;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Time Corrections — "my record for that day is wrong, here is what it should
 * say, and why". Approving rewrites the day through the same calculator.
 */
class TimeCorrectionController extends Controller
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TimeCorrection::class);

        $user = $request->user();
        $status = in_array($request->input('status'), TimeCorrection::STATUSES, true) ? $request->input('status') : null;

        $scoped = TimeCorrection::query()
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));

        $page = (clone $scoped)
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,user_id,supervisor_id', 'decider:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('work_date')
            ->paginate(15)
            ->withQueryString();

        // What each day says right now, read live so the approver decides
        // against the record as it stands, in one query for the page.
        $onPage = $page->getCollection();

        $current = $onPage->isEmpty() ? collect() : AttendanceLog::query()
            ->whereIn('employee_id', $onPage->pluck('employee_id')->unique())
            ->between(
                $onPage->min(fn ($c) => $c->work_date->toDateString()),
                $onPage->max(fn ($c) => $c->work_date->toDateString()),
            )
            ->get()
            ->keyBy(fn (AttendanceLog $log) => $log->employee_id.'|'.$log->work_date->toDateString());

        $corrections = $page->through(function (TimeCorrection $correction) use ($user, $current) {
            $log = $current[$correction->employee_id.'|'.$correction->work_date->toDateString()] ?? null;

            return [
                'id' => $correction->id,
                'employee' => $correction->employee?->full_name,
                'employee_number' => $correction->employee?->employee_number,
                'work_date' => $correction->work_date->toDateString(),
                'time_in' => $correction->time_in?->format('H:i'),
                'time_out' => $correction->time_out?->format('H:i'),
                'reason' => $correction->reason,
                'status' => $correction->status,
                'decided_by' => $correction->decider?->name,
                'decision_remarks' => $correction->decision_remarks,
                'current' => $log ? [
                    'time_in' => $log->time_in?->format('H:i'),
                    'time_out' => $log->time_out?->format('H:i'),
                    'status' => $log->status,
                ] : null,
                'can' => [
                    'decide' => $user->can('decide', $correction),
                    'cancel' => $user->can('cancel', $correction),
                ],
            ];
        });

        return Inertia::render('HR/Timekeeping/Corrections', [
            'corrections' => $corrections,
            'filters' => ['status' => $status],
            'summary' => [
                'pending' => (clone $scoped)->where('status', TimeCorrection::STATUS_PENDING)->count(),
                'approved' => (clone $scoped)->where('status', TimeCorrection::STATUS_APPROVED)->count(),
                'rejected' => (clone $scoped)->where('status', TimeCorrection::STATUS_REJECTED)->count(),
            ],
            'can' => ['create' => $user->can('create', TimeCorrection::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', TimeCorrection::class);

        $employee = $request->user()->employee;

        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'time_in' => ['nullable', 'date_format:H:i', 'required_without:time_out'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['time_in.required_without' => 'Give at least the time-in or the time-out that should be on the record.']);

        $date = Carbon::parse($data['work_date'])->startOfDay();
        $this->timekeeping->assertOpen($date);

        $open = TimeCorrection::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->where('status', TimeCorrection::STATUS_PENDING)
            ->exists();

        if ($open) {
            return back()->withErrors(['work_date' => 'You already have a pending correction for that day.']);
        }

        TimeCorrection::create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            'time_in' => isset($data['time_in']) ? $date->copy()->setTimeFromTimeString($data['time_in']) : null,
            'time_out' => isset($data['time_out']) ? $date->copy()->setTimeFromTimeString($data['time_out']) : null,
            'reason' => $data['reason'],
            'status' => TimeCorrection::STATUS_PENDING,
        ]);

        return back()->with('success', 'Correction filed. Your record changes once it is approved.');
    }

    public function decide(Request $request, TimeCorrection $correction): RedirectResponse
    {
        Gate::authorize('decide', $correction);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'required_if:decision,reject', 'string', 'max:500'],
        ], ['remarks.required_if' => 'Say why it is rejected, so the employee knows.']);

        $approve = $data['decision'] === 'approve';
        $this->timekeeping->decideCorrection($correction, $request->user(), $approve, $data['remarks'] ?? null);

        return back()->with('success', $approve ? 'Correction approved and applied to the record.' : 'Correction rejected.');
    }

    public function cancel(TimeCorrection $correction): RedirectResponse
    {
        Gate::authorize('cancel', $correction);

        $correction->update(['status' => TimeCorrection::STATUS_CANCELLED]);

        return back()->with('success', 'Correction cancelled.');
    }
}
