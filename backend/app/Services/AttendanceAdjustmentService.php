<?php

namespace App\Services;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 2 — the exception-handling step.
 *
 * A discrepancy on a DTR (no time-out, a day nobody keyed, a status that
 * should say on leave) becomes a request rather than an edit, and a
 * supervisor or HR decides before it reaches the log.
 */
class AttendanceAdjustmentService
{
    public function __construct(
        private readonly TimekeepingService $timekeeping,
        private readonly EmployeeService $employees,
    ) {}

    /** Requests the viewer may see, mirroring the DTR itself. */
    public function scopedQuery(User $user): Builder
    {
        return AttendanceAdjustment::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,suffix,supervisor_id',
                'requester:id,name',
                'decider:id,name',
            ])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    public function file(Employee $employee, User $requester, array $data): AttendanceAdjustment
    {
        return AttendanceAdjustment::create([
            'employee_id' => $employee->id,
            'log_date' => Carbon::parse($data['log_date'])->startOfDay(),
            'requested_time_in' => $data['requested_time_in'] ?? null,
            'requested_break_out' => $data['requested_break_out'] ?? null,
            'requested_break_in' => $data['requested_break_in'] ?? null,
            'requested_time_out' => $data['requested_time_out'] ?? null,
            'requested_status' => $data['requested_status'] ?? null,
            'reason' => $data['reason'],
            'status' => AttendanceAdjustment::STATUS_PENDING,
            'requested_by' => $requester->id,
        ]);
    }

    /**
     * Approving *applies* the correction; rejecting records the refusal.
     *
     * The write goes through `TimekeepingService::record()` like every other
     * change to a time record, so an approved adjustment is recomputed from
     * its punches by the same calculator payroll depends on. A second write
     * path here would eventually disagree with the DTR screen about the same
     * day's overtime.
     *
     * Both halves in one transaction: an approval whose apply failed would
     * leave a request marked approved and a DTR that never changed, which is
     * the worst of the three possible states because it looks handled.
     */
    public function decide(
        AttendanceAdjustment $request,
        User $decider,
        string $status,
        ?string $remarks = null,
    ): AttendanceAdjustment {
        return DB::transaction(function () use ($request, $decider, $status, $remarks) {
            $request->update([
                'status' => $status,
                'decided_by' => $decider->id,
                'decided_at' => now(),
                'remarks' => $remarks,
            ]);

            if ($status === AttendanceAdjustment::STATUS_APPROVED) {
                $this->apply($request);
            }

            return $request->refresh();
        });
    }

    public function cancel(AttendanceAdjustment $request): AttendanceAdjustment
    {
        $request->update(['status' => AttendanceAdjustment::STATUS_CANCELLED]);

        return $request->refresh();
    }

    /**
     * What the day currently says, so an approver decides against the record
     * as it stands rather than against a snapshot taken when the request was
     * filed. A day nobody has keyed at all returns null, which is a real
     * answer — the request is to create the row, not to correct one.
     */
    public function currentLog(AttendanceAdjustment $request): ?AttendanceLog
    {
        return AttendanceLog::query()
            ->with('shift:id,name')
            ->where('employee_id', $request->employee_id)
            ->whereDate('log_date', $request->log_date)
            ->first();
    }

    /**
     * Writes the approved correction into the DTR.
     *
     * A punch the request left blank is *left alone* rather than cleared: a
     * request to add a missing time-out says nothing about the time-in, and
     * reading its blank as "erase this" would destroy the half of the day
     * that was already right.
     */
    private function apply(AttendanceAdjustment $request): void
    {
        $employee = $request->employee;

        if ($employee === null) {
            return;
        }

        $existing = $this->currentLog($request);

        $punch = fn (string $field) => $request->{"requested_{$field}"}
            ?? $existing?->{$field}?->format('H:i');

        $this->timekeeping->record($employee, [
            'log_date' => $request->log_date->toDateString(),
            'time_in' => $punch('time_in'),
            'break_out' => $punch('break_out'),
            'break_in' => $punch('break_in'),
            'time_out' => $punch('time_out'),
            'status' => $request->requested_status,
            'source' => 'manual',
            // Says on the row itself that this day was corrected rather than
            // clocked, which is the question anybody reading it back later
            // actually has.
            'remarks' => trim('Adjustment #'.$request->id.': '.$request->reason),
        ]);
    }
}
