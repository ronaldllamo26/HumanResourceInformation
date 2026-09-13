<?php

namespace App\Services;

use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Overtime filing and approval.
 *
 * Attendance records raw time worked past the shift; this is the record of what
 * was *authorised*. Payroll pays the approved hours, not the raw ones.
 */
class OvertimeService
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function scopedQuery(User $user): Builder
    {
        return OvertimeRequest::query()
            ->with(['employee:id,employee_number,first_name,middle_name,last_name,suffix', 'approver:id,name'])
            ->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id'));
    }

    public function file(array $data): OvertimeRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        [$start, $end] = $this->window($date, $data['start_time'], $data['end_time']);

        return OvertimeRequest::create([
            'employee_id' => $data['employee_id'],
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => round($start->diffInMinutes($end) / 60, 2),
            'reason' => $data['reason'],
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);
    }

    public function update(OvertimeRequest $request, array $data): OvertimeRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        [$start, $end] = $this->window($date, $data['start_time'], $data['end_time']);

        $request->update([
            'employee_id' => $data['employee_id'],
            'date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => round($start->diffInMinutes($end) / 60, 2),
            'reason' => $data['reason'],
        ]);

        return $request->refresh();
    }

    public function decide(
        OvertimeRequest $request,
        User $approver,
        string $status,
        ?string $remarks = null,
    ): OvertimeRequest {
        $request->update([
            'status' => $status,
            'approved_by' => $approver->id,
            'acted_at' => now(),
            'remarks' => $remarks,
        ]);

        return $request->refresh();
    }

    public function cancel(OvertimeRequest $request): OvertimeRequest
    {
        $request->update([
            'status' => OvertimeRequest::STATUS_CANCELLED,
            'acted_at' => now(),
        ]);

        return $request->refresh();
    }

    /** Approved overtime hours in a range — the figure Payroll consumes. */
    public function approvedHours(Builder $query): float
    {
        return round(
            (float) (clone $query)->reorder()
                ->where('status', OvertimeRequest::STATUS_APPROVED)
                ->sum('hours'),
            2,
        );
    }

    public function summary(Builder $query): array
    {
        $rows = (clone $query)->reorder()->get(['status', 'hours']);

        return [
            'total' => $rows->count(),
            'pending' => $rows->where('status', OvertimeRequest::STATUS_PENDING)->count(),
            'approved' => $rows->where('status', OvertimeRequest::STATUS_APPROVED)->count(),
            'approved_hours' => round(
                (float) $rows->where('status', OvertimeRequest::STATUS_APPROVED)->sum('hours'),
                2,
            ),
        ];
    }

    /**
     * Anchors the filed times to the date. An end at or before the start belongs
     * to the following day — overtime routinely runs past midnight.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Carbon $date, string $startTime, string $endTime): array
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $startTime));
        [$endHour, $endMinute] = array_map('intval', explode(':', $endTime));

        $start = $date->copy()->setTime($startHour, $startMinute);
        $end = $date->copy()->setTime($endHour, $endMinute);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }
}
