<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
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
 * Overtime Requests — filed by whoever worked it, decided by HR or their
 * supervisor, and paid only once approved.
 */
class OvertimeController extends Controller
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OvertimeRequest::class);

        $user = $request->user();
        $status = in_array($request->input('status'), OvertimeRequest::STATUSES, true) ? $request->input('status') : null;

        $scoped = OvertimeRequest::query()
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));

        $requests = (clone $scoped)
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix,user_id,supervisor_id', 'decider:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('work_date')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (OvertimeRequest $overtime) => [
                'id' => $overtime->id,
                'employee' => $overtime->employee?->full_name,
                'employee_number' => $overtime->employee?->employee_number,
                'work_date' => $overtime->work_date->toDateString(),
                'hours' => (float) $overtime->hours,
                'reason' => $overtime->reason,
                'status' => $overtime->status,
                'decided_by' => $overtime->decider?->name,
                'decided_at' => $overtime->decided_at?->toDateTimeString(),
                'decision_remarks' => $overtime->decision_remarks,
                'recorded_overtime_minutes' => $this->recordedOvertime($overtime),
                'can' => [
                    'decide' => $user->can('decide', $overtime),
                    'cancel' => $user->can('cancel', $overtime),
                ],
            ]);

        $counts = (clone $scoped)->selectRaw('status, count(*) as total, sum(hours) as hours')->groupBy('status')->get()->keyBy('status');

        return Inertia::render('HR/Timekeeping/Overtime', [
            'requests' => $requests,
            'filters' => ['status' => $status],
            'summary' => [
                'pending' => (int) ($counts['pending']->total ?? 0),
                'approved_hours' => round((float) ($counts['approved']->hours ?? 0), 2),
                'rejected' => (int) ($counts['rejected']->total ?? 0),
            ],
            'can' => ['create' => $user->can('create', OvertimeRequest::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', OvertimeRequest::class);

        $employee = $request->user()->employee;

        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:'.now()->addDays(30)->toDateString()],
            'hours' => ['required', 'numeric', 'min:0.5', 'max:12'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $date = Carbon::parse($data['work_date'])->startOfDay();
        $this->timekeeping->assertOpen($date);

        $duplicate = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->whereIn('status', [OvertimeRequest::STATUS_PENDING, OvertimeRequest::STATUS_APPROVED])
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['work_date' => 'You already have an open or approved overtime request for that day.']);
        }

        OvertimeRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            'hours' => $data['hours'],
            'reason' => $data['reason'],
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);

        return back()->with('success', 'Overtime request filed. It is paid once approved.');
    }

    public function decide(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        Gate::authorize('decide', $overtime);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'required_if:decision,reject', 'string', 'max:500'],
        ], ['remarks.required_if' => 'Say why it is rejected, so the employee knows.']);

        $approve = $data['decision'] === 'approve';
        $this->timekeeping->decideOvertime($overtime, $request->user(), $approve, $data['remarks'] ?? null);

        return back()->with('success', $approve ? 'Overtime approved.' : 'Overtime rejected.');
    }

    public function cancel(OvertimeRequest $overtime): RedirectResponse
    {
        Gate::authorize('cancel', $overtime);

        $overtime->update(['status' => OvertimeRequest::STATUS_CANCELLED]);

        return back()->with('success', 'Overtime request cancelled.');
    }

    /** What the DTR shows past the shift that day, so the approver can compare. */
    private function recordedOvertime(OvertimeRequest $overtime): ?int
    {
        return $this->timekeeping->scopedLogs(request()->user())
            ->where('employee_id', $overtime->employee_id)
            ->whereDate('work_date', $overtime->work_date->toDateString())
            ->value('overtime_minutes');
    }
}
