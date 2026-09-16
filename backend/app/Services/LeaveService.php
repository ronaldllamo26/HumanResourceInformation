<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Module 3 — Leave & Absence.
 *
 * Two-step approval: employee files, the supervisor endorses, HR confirms.
 * Credits are only spent at the final HR step, but days on open requests are
 * held back so the same credit cannot be filed against twice.
 */
class LeaveService
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly WorkCalendar $calendar,
    ) {}

    public function scopedQuery(User $user): Builder
    {
        return LeaveRequest::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,suffix,department_id,supervisor_id',
                'leaveType:id,code,name,is_paid',
                'supervisor:id,name',
                'hr:id,name',
            ])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    /**
     * Working days in the range: holidays and the employee's own rest days do
     * not consume credits. A half day is always 0.5 and must sit on a single
     * date.
     *
     * The calendar is Time & Attendance's (`WorkCalendar`), so leave, the DTR
     * and payroll agree about which Tuesday was a working day. Somebody with
     * no shift assigned rests on Saturday and Sunday.
     */
    public function workingDays(Employee $employee, Carbon $start, Carbon $end, bool $isHalfDay = false): float
    {
        if ($isHalfDay) {
            return 0.5;
        }

        $days = 0.0;

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            if ($this->calendar->isWorkingDay($employee, $date)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * Which days inside the range an approved leave covers, keyed
     * `employeeId|Y-m-d`.
     *
     * This is the join between Modules 2 and 3, and it is what tells an
     * absence from an AWOL. Without it the two modules each hold half the
     * answer: attendance knows somebody did not come in, leave knows they
     * were allowed not to, and nothing put the two together — so payroll
     * deducted the absence *and* the unpaid leave for the same day, and a
     * paid VL was docked as though it were unexcused.
     *
     * One query for however many employees. Expanded in PHP rather than by a
     * date-generating join, because the expansion is a calendar walk and SQL
     * dialects disagree about how to do one.
     *
     * A half day still counts as covered: the person was accounted for, which
     * is the question this answers. How much of the day was worked is
     * AttendanceCalculator's, off the punches.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<string, array{leave_type: string, is_paid: bool, request_id: int, is_half_day: bool}>
     */
    public function approvedLeaveDates(array $employeeIds, Carbon $from, Carbon $to): Collection
    {
        $covered = collect();

        LeaveRequest::query()
            ->with('leaveType:id,code,name,is_paid')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get()
            ->each(function (LeaveRequest $request) use ($covered, $from, $to) {
                // Only the part of the request that falls inside the range —
                // a leave straddling the cutoff belongs to both periods, and
                // each may only claim its own days.
                $start = $request->start_date->copy()->max($from);
                $end = $request->end_date->copy()->min($to);

                for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                    $covered->put("{$request->employee_id}|{$date->toDateString()}", [
                        'leave_type' => $request->leaveType?->name ?? 'Leave',
                        'is_paid' => (bool) $request->leaveType?->is_paid,
                        'request_id' => $request->id,
                        'is_half_day' => (bool) $request->is_half_day,
                    ]);
                }
            });

        return $covered;
    }

    public function balanceFor(Employee $employee, LeaveType $type, ?int $year = null): LeaveBalance
    {
        $year ??= now()->year;

        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
            ['credits_earned' => $type->default_credits, 'credits_used' => 0, 'credits_carried_over' => 0],
        );
    }

    /**
     * Credits the employee can still file against — spent credits minus the days
     * already committed to requests that are open but not yet deducted.
     */
    public function availableCredits(Employee $employee, LeaveType $type, ?int $year = null): float
    {
        $year ??= now()->year;
        $balance = $this->balanceFor($employee, $type, $year);

        $reserved = (float) LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_SUPERVISOR_APPROVED])
            ->whereYear('start_date', $year)
            ->sum('days_requested');

        return round($balance->available() - $reserved, 2);
    }

    public function file(Employee $employee, LeaveType $type, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $type, $data) {
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $end = Carbon::parse($data['end_date'])->startOfDay();
            $isHalfDay = (bool) ($data['is_half_day'] ?? false);

            return LeaveRequest::create([
                'reference_number' => $this->nextReferenceNumber(),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start,
                'end_date' => $end,
                'days_requested' => $this->workingDays($employee, $start, $end, $isHalfDay),
                'is_half_day' => $isHalfDay,
                'half_day_period' => $isHalfDay ? ($data['half_day_period'] ?? 'morning') : null,
                'reason' => $data['reason'],
                'attachment_path' => $data['attachment_path'] ?? null,
                'status' => LeaveRequest::STATUS_PENDING,
            ]);
        });
    }

    /**
     * Approves a request and spends the credits.
     *
     * One step. It was two — a supervisor endorsed, then HR confirmed — and
     * only ever the second one moved credits; the first bought a delay rather
     * than a decision. Requests still sitting in `supervisor_approved` from
     * before that change are approved through here too, which is why the
     * status is not gone from the model.
     */
    /**
     * Why approving this request would overdraw the balance, or null if it
     * would not. Unpaid leave never touches credits, so it never falls short.
     */
    public function creditShortfall(LeaveRequest $request): ?string
    {
        $type = $request->leaveType;

        if (! $type?->is_paid) {
            return null;
        }

        $available = $this->balanceFor($request->employee, $type, $request->start_date->year)->available();
        $requested = (float) $request->days_requested;

        if ($requested <= $available) {
            return null;
        }

        return "Cannot approve: only {$available} day(s) of {$type->name} remain, and this request needs {$requested}.";
    }

    public function approve(LeaveRequest $request, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approver, $remarks) {
            $request->update([
                'status' => LeaveRequest::STATUS_APPROVED,
                'hr_id' => $approver->id,
                'hr_acted_at' => now(),
                'hr_remarks' => $remarks,
            ]);

            $this->applyCredits($request, spend: true);

            return $request->refresh();
        });
    }

    public function reject(LeaveRequest $request, User $approver, ?string $remarks = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approver, $remarks) {
            $wasApproved = $request->status === LeaveRequest::STATUS_APPROVED;

            $request->update([
                'status' => LeaveRequest::STATUS_REJECTED,
                'hr_id' => $approver->id,
                'hr_acted_at' => now(),
                'hr_remarks' => $remarks,
            ]);

            if ($wasApproved) {
                $this->applyCredits($request, spend: false);
            }

            return $request->refresh();
        });
    }

    public function cancel(LeaveRequest $request): LeaveRequest
    {
        return DB::transaction(function () use ($request) {
            $wasApproved = $request->status === LeaveRequest::STATUS_APPROVED;

            $request->update(['status' => LeaveRequest::STATUS_CANCELLED]);

            // Cancelling an already-approved leave hands the credits back.
            if ($wasApproved) {
                $this->applyCredits($request, spend: false);
            }

            return $request->refresh();
        });
    }

    /** Requests this user is the next approver for. Drives the topbar badge. */
    /**
     * How many requests are waiting on *this* user — the topbar bell.
     *
     * Only HR sees a count now. Supervisors used to be counted here for the
     * requests of their own reports, and that was right while endorsing was
     * a step they took; with the decision HR's alone, a badge they cannot act
     * on is a badge that teaches them to ignore the bell.
     *
     * Both open statuses count. `supervisor_approved` no longer happens, but
     * a row left in it is somebody still waiting.
     */
    public function pendingApprovalsFor(User $user): int
    {
        if (! $user->isHrAdmin()) {
            return 0;
        }

        return LeaveRequest::whereIn('status', [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_SUPERVISOR_APPROVED,
        ])->count();
    }

    public function summary(Builder $query): array
    {
        $rows = (clone $query)->reorder()->get(['status', 'days_requested']);

        return [
            'total' => $rows->count(),
            'pending' => $rows->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_SUPERVISOR_APPROVED,
            ])->count(),
            'approved' => $rows->where('status', LeaveRequest::STATUS_APPROVED)->count(),
            'approved_days' => round(
                (float) $rows->where('status', LeaveRequest::STATUS_APPROVED)->sum('days_requested'),
                2,
            ),
        ];
    }

    /**
     * Approved and endorsed leave overlapping a range, expanded to one entry per
     * date so the calendar can render a day at a time.
     *
     * @return Collection<string, array<int, array<string, mixed>>>
     */
    public function calendarEntries(Builder $query, Carbon $from, Carbon $to): Collection
    {
        $requests = (clone $query)
            ->reorder()
            ->whereIn('status', [
                LeaveRequest::STATUS_APPROVED,
                LeaveRequest::STATUS_SUPERVISOR_APPROVED,
            ])
            ->overlapping($from->toDateString(), $to->toDateString())
            ->get();

        $entries = [];

        foreach ($requests as $request) {
            $cursor = $request->start_date->copy()->max($from);
            $last = $request->end_date->copy()->min($to);

            for (; $cursor->lessThanOrEqualTo($last); $cursor->addDay()) {
                $entries[$cursor->toDateString()][] = [
                    'id' => $request->id,
                    'employee' => $request->employee?->full_name,
                    'type_code' => $request->leaveType?->code,
                    'type_name' => $request->leaveType?->name,
                    'status' => $request->status,
                    'is_half_day' => $request->is_half_day,
                ];
            }
        }

        return collect($entries);
    }

    /** Moves credits for a request, in either direction. */
    private function applyCredits(LeaveRequest $request, bool $spend): void
    {
        $type = $request->leaveType;

        // Unpaid leave never touches the credit ledger.
        if (! $type?->is_paid) {
            return;
        }

        $balance = $this->balanceFor(
            $request->employee,
            $type,
            $request->start_date->year,
        );

        $used = (float) $balance->credits_used + ((float) $request->days_requested * ($spend ? 1 : -1));

        $balance->update(['credits_used' => max(0, round($used, 2))]);
    }

    private function nextReferenceNumber(): string
    {
        $year = now()->year;
        $prefix = "LV-{$year}-";

        $latest = LeaveRequest::where('reference_number', 'like', $prefix.'%')
            ->orderByDesc('reference_number')
            ->value('reference_number');

        $sequence = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
