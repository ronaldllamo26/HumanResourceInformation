<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceAdjustmentRequest;
use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use App\Services\AttendanceAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2 — DTR correction requests (the exception-handling step).
 */
class AttendanceAdjustmentController extends Controller
{
    public function __construct(private readonly AttendanceAdjustmentService $adjustments) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AttendanceAdjustment::class);

        // A work queue opens on what is waiting; `all` is an explicit choice,
        // the same default the endorsement inbox takes.
        $filters = ['status' => $request->query('status', AttendanceAdjustment::STATUS_PENDING)];

        $query = $this->adjustments->scopedQuery($request->user())
            ->filter($filters === ['status' => 'all'] ? [] : $filters);

        $requests = (clone $query)
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('log_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('HR/Timekeeping/Adjustments', [
            'requests' => $requests->through(fn (AttendanceAdjustment $row) => [
                'id' => $row->id,
                'employee_name' => $row->employee?->full_name ?? '—',
                'employee_number' => $row->employee?->employee_number,
                'log_date' => $row->log_date->toDateString(),
                'requested' => [
                    'time_in' => $this->hhmm($row->requested_time_in),
                    'break_out' => $this->hhmm($row->requested_break_out),
                    'break_in' => $this->hhmm($row->requested_break_in),
                    'time_out' => $this->hhmm($row->requested_time_out),
                    'status' => $row->requested_status,
                ],
                /*
                 * Read live rather than snapshotted when the request was
                 * filed, so an approver decides against the record as it
                 * stands. A day nobody keyed comes back null — a real answer,
                 * and the difference between "correct this" and "create this".
                 */
                'current' => $this->current($row),
                'reason' => $row->reason,
                'status' => $row->status,
                'requested_by' => $row->requester?->name,
                'decided_by' => $row->decider?->name,
                'decided_at' => $row->decided_at?->toIso8601String(),
                'remarks' => $row->remarks,
                'can' => [
                    'decide' => $request->user()->can('decide', $row),
                    'cancel' => $request->user()->can('cancel', $row),
                ],
            ]),
            'summary' => [
                'pending' => (clone $this->adjustments->scopedQuery($request->user()))
                    ->where('status', AttendanceAdjustment::STATUS_PENDING)->count(),
                'approved' => (clone $this->adjustments->scopedQuery($request->user()))
                    ->where('status', AttendanceAdjustment::STATUS_APPROVED)->count(),
                'rejected' => (clone $this->adjustments->scopedQuery($request->user()))
                    ->where('status', AttendanceAdjustment::STATUS_REJECTED)->count(),
            ],
            'filters' => $filters,
            'statuses' => AttendanceAdjustment::STATUSES,
            'attendanceStatuses' => AttendanceLog::STATUSES,
            'can' => [
                'create' => $request->user()->can('create', AttendanceAdjustment::class),
            ],
        ]);
    }

    public function store(StoreAttendanceAdjustmentRequest $request): RedirectResponse
    {
        // Their own record, resolved from the login rather than posted. The
        // form has no employee picker and the endpoint accepts no id.
        $this->adjustments->file(
            $request->user()->employee,
            $request->user(),
            $request->validated(),
        );

        return back()->with('success', 'Correction requested. It needs a decision before it changes the record.');
    }

    /** Approve or reject — the approver may not be the requester. */
    public function decide(Request $request, AttendanceAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('decide', $adjustment);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                AttendanceAdjustment::STATUS_APPROVED,
                AttendanceAdjustment::STATUS_REJECTED,
            ])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->adjustments->decide(
            $adjustment,
            $request->user(),
            $validated['status'],
            $validated['remarks'] ?? null,
        );

        return back()->with(
            'success',
            $validated['status'] === AttendanceAdjustment::STATUS_APPROVED
                ? 'Correction approved and applied to the time record.'
                : 'Correction rejected. The time record is unchanged.',
        );
    }

    public function cancel(AttendanceAdjustment $adjustment): RedirectResponse
    {
        Gate::authorize('cancel', $adjustment);

        $this->adjustments->cancel($adjustment);

        return back()->with('success', 'Correction request cancelled.');
    }

    /** @return array<string, mixed>|null */
    private function current(AttendanceAdjustment $adjustment): ?array
    {
        $log = $this->adjustments->currentLog($adjustment);

        return $log === null ? null : [
            'time_in' => $log->time_in?->format('H:i'),
            'break_out' => $log->break_out?->format('H:i'),
            'break_in' => $log->break_in?->format('H:i'),
            'time_out' => $log->time_out?->format('H:i'),
            'status' => $log->status,
            'hours_worked' => round((float) $log->hours_worked, 2),
        ];
    }

    /** A `time` column comes back as "08:00:00"; the screen wants "08:00". */
    private function hhmm(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
