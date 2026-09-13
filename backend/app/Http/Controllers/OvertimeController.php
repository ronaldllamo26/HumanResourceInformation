<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOvertimeRequestRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Services\EmployeeService;
use App\Services\OvertimeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — overtime filing and approval.
 */
class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtime,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OvertimeRequest::class);

        $filters = [
            'status' => $request->query('status'),
            'employee_id' => $request->query('employee_id'),
        ];

        $query = $this->overtime->scopedQuery($request->user())
            ->when($filters['status'], fn ($q, $value) => $q->where('status', $value))
            ->when($filters['employee_id'], fn ($q, $value) => $q->where('employee_id', $value));

        $requests = (clone $query)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Timekeeping/Overtime', [
            'requests' => OvertimeRequestResource::collection($requests),
            'summary' => $this->overtime->summary($query),
            'filters' => $filters,
            'statuses' => OvertimeRequest::STATUSES,
            'employees' => $this->employees->scopedQuery($request->user())
                ->orderBy('last_name')
                ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                ]),
            /*
             * `employees` feeds the *filter*, not the form. HR and supervisors
             * read other people's requests here; nobody files one for them.
             */
            'can' => [
                'create' => $request->user()->can('create', OvertimeRequest::class),
            ],
            'ownEmployeeId' => $request->user()->employee?->id,
        ]);
    }

    public function store(StoreOvertimeRequestRequest $request): RedirectResponse
    {
        $overtime = $this->overtime->file($request->validated());

        return back()->with(
            'success',
            "Overtime request filed for {$overtime->date->toFormattedDateString()} ({$overtime->hours} hours).",
        );
    }

    public function update(StoreOvertimeRequestRequest $request, OvertimeRequest $overtimeRequest): RedirectResponse
    {
        Gate::authorize('update', $overtimeRequest);

        $this->overtime->update($overtimeRequest, $request->validated());

        return back()->with('success', 'Overtime request updated.');
    }

    /** Approve or reject — the approver may not be the requester. */
    public function decide(Request $request, OvertimeRequest $overtimeRequest): RedirectResponse
    {
        Gate::authorize('decide', $overtimeRequest);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                OvertimeRequest::STATUS_APPROVED,
                OvertimeRequest::STATUS_REJECTED,
            ])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->overtime->decide(
            $overtimeRequest,
            $request->user(),
            $validated['status'],
            $validated['remarks'] ?? null,
        );

        return back()->with('success', "Overtime request {$validated['status']}.");
    }

    public function cancel(OvertimeRequest $overtimeRequest): RedirectResponse
    {
        Gate::authorize('cancel', $overtimeRequest);

        $this->overtime->cancel($overtimeRequest);

        return back()->with('success', 'Overtime request cancelled.');
    }
}
