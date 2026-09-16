<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\EmployeeService;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 3 — Leave & Absence.
 * Filing, the two-step approval workflow, and the attachment download.
 */
class LeaveController extends Controller
{
    /** Medical certificates and the like are never web-served directly. */
    private const ATTACHMENT_DISK = 'local';

    public function __construct(
        private readonly LeaveService $leave,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        // `awaiting` and `filed_from` are set by summary tiles rather than by
        // any control on this screen — the first matches the two statuses the
        // tile counts, the second the month the dashboard's Leave card counts.
        // See LeaveRequest::scopeFilter, and the chips beside the filter row.
        $filters = $request->only([
            'status', 'leave_type_id', 'employee_id', 'from', 'to',
            'awaiting', 'filed_from',
        ]);

        $query = $this->leave->scopedQuery($request->user())->filter($filters);

        $requests = (clone $query)
            // Anything still awaiting a decision floats to the top.
            ->orderByRaw("case when status in ('pending','supervisor_approved') then 0 else 1 end")
            ->orderByDesc('start_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Leave/Index', [
            'requests' => LeaveRequestResource::collection($requests),
            'summary' => $this->leave->summary($query),
            'filters' => $filters,
            'statuses' => LeaveRequest::STATUSES,
            'leaveTypes' => LeaveType::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_paid', 'requires_attachment', 'min_days_notice']),
            'employees' => $this->employeeOptions($request),
            'myBalances' => $this->balancesForOwnEmployee($request),
            'can' => [
                'create' => $request->user()->can('create', LeaveRequest::class),
                // Deciding is HR's alone now — the supervisor step is gone,
                // and so is filing on somebody else's behalf.
                'decide' => $request->user()->isHrAdmin(),
            ],
            'ownEmployeeId' => $request->user()->employee?->id,
        ]);
    }

    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $type = LeaveType::findOrFail($request->validated('leave_type_id'));

        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')
                ->store("leave-attachments/{$employee->id}", self::ATTACHMENT_DISK);
        }

        $leaveRequest = $this->leave->file($employee, $type, $data);

        return back()->with(
            'success',
            "Leave {$leaveRequest->reference_number} filed for {$leaveRequest->days_requested} day(s).",
        );
    }

    /** Supervisor endorsement, then HR confirmation — decided by current status. */
    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('decide', $leaveRequest);

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        /*
         * Credits are checked again at approval, not only at filing. A balance
         * can shrink in between — corrected by HR, or spent by another request
         * approved first — and approving anyway would push it negative.
         */
        $shortfall = $this->leave->creditShortfall($leaveRequest);

        if ($shortfall !== null) {
            return back()->with('error', $shortfall);
        }

        $this->leave->approve($leaveRequest, $request->user(), $validated['remarks'] ?? null);

        return back()->with('success', 'Leave approved and credits deducted.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('reject', $leaveRequest);

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Give a reason for the rejection.',
        ]);

        $this->leave->reject($leaveRequest, $request->user(), $validated['remarks']);

        return back()->with('success', 'Leave request rejected.');
    }

    public function cancel(LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('cancel', $leaveRequest);

        $this->leave->cancel($leaveRequest);

        return back()->with('success', 'Leave request cancelled.');
    }

    public function attachment(LeaveRequest $leaveRequest): StreamedResponse
    {
        Gate::authorize('view', $leaveRequest);

        abort_if(blank($leaveRequest->attachment_path), 404);

        $disk = Storage::disk(self::ATTACHMENT_DISK);

        abort_unless($disk->exists($leaveRequest->attachment_path), 404);

        return $disk->download(
            $leaveRequest->attachment_path,
            "{$leaveRequest->reference_number}-attachment.".pathinfo($leaveRequest->attachment_path, PATHINFO_EXTENSION),
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function employeeOptions(Request $request)
    {
        return $this->employees->scopedQuery($request->user())
            ->orderBy('last_name')
            ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
            ]);
    }

    /** The filing form shows the requester what they have left. */
    private function balancesForOwnEmployee(Request $request): array
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return [];
        }

        return LeaveType::where('is_active', true)
            ->get()
            ->map(fn (LeaveType $type) => [
                'leave_type_id' => $type->id,
                'code' => $type->code,
                'available' => $this->leave->availableCredits($employee, $type),
            ])
            ->all();
    }
}
