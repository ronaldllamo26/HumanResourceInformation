<?php

namespace App\Services;

use App\Models\AttendanceCutoff;
use App\Models\AttendanceLog;
use App\Models\OvertimeRequest;
use App\Models\PayrollPeriod;
use App\Models\TimeCorrection;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Closing a payroll period's attendance.
 *
 * Closing freezes every day inside the period — `TimekeepingService::assertOpen()`
 * refuses records, overtime decisions and corrections dated there — so the
 * figures payroll computes from cannot move after they were checked.
 *
 * A cutoff is refused while anything in it is still undecided, because after
 * closing nothing could decide it: an incomplete punch could not be completed,
 * and a pending overtime request could never be approved and would never be
 * paid.
 */
class AttendanceCutoffService
{
    public function forPeriod(PayrollPeriod $period): AttendanceCutoff
    {
        return AttendanceCutoff::firstOrNew(
            ['payroll_period_id' => $period->id],
            ['status' => AttendanceCutoff::STATUS_OPEN],
        );
    }

    /** What still has to be settled before the period can close. @return array<string, int> */
    public function outstanding(PayrollPeriod $period): array
    {
        $from = $period->start_date->toDateString();
        $to = $period->end_date->toDateString();

        return [
            'incomplete' => AttendanceLog::query()->between($from, $to)
                ->where('status', AttendanceLog::STATUS_INCOMPLETE)->count(),
            'pending_overtime' => OvertimeRequest::query()->where('status', OvertimeRequest::STATUS_PENDING)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->count(),
            'pending_corrections' => TimeCorrection::query()->where('status', TimeCorrection::STATUS_PENDING)
                ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->count(),
        ];
    }

    public function close(PayrollPeriod $period, User $by): AttendanceCutoff
    {
        $cutoff = $this->forPeriod($period);

        if ($cutoff->isClosed()) {
            throw ValidationException::withMessages(['cutoff' => 'This cutoff is already closed.']);
        }

        $outstanding = $this->outstanding($period);

        if (array_sum($outstanding) > 0) {
            throw ValidationException::withMessages([
                'cutoff' => sprintf(
                    'Settle these first — %d day(s) with no time-out, %d overtime request(s) and %d correction(s) still pending. Once closed, none of them could be decided.',
                    $outstanding['incomplete'],
                    $outstanding['pending_overtime'],
                    $outstanding['pending_corrections'],
                ),
            ]);
        }

        $cutoff->fill([
            'status' => AttendanceCutoff::STATUS_CLOSED,
            'closed_by' => $by->id,
            'closed_at' => now(),
        ])->save();

        return $cutoff;
    }

    public function reopen(PayrollPeriod $period, User $by, string $reason): AttendanceCutoff
    {
        $cutoff = $this->forPeriod($period);

        if (! $cutoff->isClosed()) {
            throw ValidationException::withMessages(['cutoff' => 'This cutoff is not closed.']);
        }

        $cutoff->fill([
            'status' => AttendanceCutoff::STATUS_OPEN,
            'reopened_by' => $by->id,
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ])->save();

        return $cutoff;
    }
}
