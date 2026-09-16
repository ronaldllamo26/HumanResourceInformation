<?php

namespace App\Services;

use App\Models\EmployeeDocument;
use App\Models\EmployeeEndorsement;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\TimeCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the topbar bell lists for one person.
 *
 * Built from things this system already tracks — nothing is stored as a
 * "notification", so nothing can go stale or need marking read. Each entry
 * links to the screen where it is acted on, and each is scoped by the same
 * rules as that screen: HR sees every queue, a supervisor sees their direct
 * reports', everyone sees their own leave decisions and lapsing documents.
 */
class NotificationFeed
{
    private const RECENT_DECISION_DAYS = 14;

    private const LEAVE_PREVIEW = 5;

    public function __construct(
        private readonly EmployeeService $employees,
        private readonly CredentialExpiryScanner $credentials,
    ) {}

    /** How many things are waiting on this person to act — the red badge. */
    public function count(User $user): int
    {
        return $this->pendingLeave($user)->count()
            + $this->pendingHires($user)
            + $this->awaitingDecision($user, OvertimeRequest::query())->count()
            + $this->awaitingDecision($user, TimeCorrection::query())->count();
    }

    /** @return array{items: array<int, array<string, mixed>>, count: int} */
    public function for(User $user): array
    {
        $items = [];

        foreach ($this->pendingLeave($user)->with(['employee', 'leaveType'])->latest('id')->limit(self::LEAVE_PREVIEW)->get() as $leave) {
            $items[] = [
                'key' => "leave-{$leave->id}",
                'kind' => 'leave',
                'tone' => 'warning',
                'title' => "{$leave->employee?->full_name} filed {$leave->leaveType?->name}",
                'detail' => $leave->start_date?->format('M j').' – '.$leave->end_date?->format('M j').' · '.(float) $leave->days_requested.' day(s)',
                'href' => '/hr/leave?status=pending',
                'at' => $leave->created_at?->toIso8601String(),
            ];
        }

        $moreLeave = $this->pendingLeave($user)->count() - self::LEAVE_PREVIEW;

        if ($moreLeave > 0) {
            $items[] = $this->summary('leave-more', 'warning', "{$moreLeave} more leave request(s) waiting", 'Open the leave queue to see them all', '/hr/leave?status=pending');
        }

        if (($overtime = $this->awaitingDecision($user, OvertimeRequest::query())->count()) > 0) {
            $items[] = $this->summary('overtime', 'warning', "{$overtime} overtime request(s) to decide", 'Only approved overtime is paid', '/hr/timekeeping/overtime?status=pending');
        }

        if (($corrections = $this->awaitingDecision($user, TimeCorrection::query())->count()) > 0) {
            $items[] = $this->summary('corrections', 'warning', "{$corrections} time correction(s) to decide", 'Employees asking for their record to be fixed', '/hr/timekeeping/corrections?status=pending');
        }

        if (($hires = $this->pendingHires($user)) > 0) {
            $items[] = $this->summary('hires', 'info', "{$hires} new hire(s) from Core 1", 'Endorsements waiting for review', '/hr/endorsements');
        }

        $expiring = $this->credentials->countFor(
            EmployeeDocument::query()->whereIn('employee_id', $this->employees->scopedQuery($user)->select('employees.id')),
        );

        if ($expiring > 0) {
            $items[] = $this->summary('credentials', 'destructive', "{$expiring} document(s) expired or expiring", 'Licences and clearances that need renewing', '/hr/credentials');
        }

        foreach ($this->recentDecisionsOnOwnLeave($user) as $leave) {
            $approved = $leave->status === LeaveRequest::STATUS_APPROVED;

            $items[] = [
                'key' => "decided-{$leave->id}",
                'kind' => 'decision',
                'tone' => $approved ? 'success' : 'destructive',
                'title' => "Your {$leave->leaveType?->name} was ".($approved ? 'approved' : 'rejected'),
                'detail' => $leave->start_date?->format('M j').' – '.$leave->end_date?->format('M j')
                    .($leave->hr_remarks ? ' · '.$leave->hr_remarks : ''),
                'href' => '/hr/leave',
                'at' => $leave->hr_acted_at?->toIso8601String(),
            ];
        }

        return ['items' => $items, 'count' => $this->count($user)];
    }

    /** Leave is HR's to decide, and never your own. */
    private function pendingLeave(User $user): Builder
    {
        if (! $user->isHrAdmin()) {
            return LeaveRequest::query()->whereRaw('1 = 0');
        }

        return LeaveRequest::query()
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_SUPERVISOR_APPROVED])
            ->whereHas('employee', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('user_id')->orWhere('user_id', '!=', $user->id),
            ));
    }

    /**
     * Pending overtime or corrections this user may decide: HR sees everyone's,
     * a supervisor their direct reports' — and nobody their own, the same line
     * the policies draw.
     */
    private function awaitingDecision(User $user, Builder $query): Builder
    {
        $query->where('status', 'pending');

        if ($user->isHrAdmin()) {
            return $query->whereHas('employee', fn (Builder $employee) => $employee->where(
                fn (Builder $inner) => $inner->whereNull('user_id')->orWhere('user_id', '!=', $user->id),
            ));
        }

        if ($user->isSupervisor() && $user->employee) {
            return $query->whereHas('employee', fn (Builder $employee) => $employee
                ->where('supervisor_id', $user->employee->id)
                ->where('id', '!=', $user->employee->id));
        }

        return $query->whereRaw('1 = 0');
    }

    private function pendingHires(User $user): int
    {
        return $user->can('viewAny', EmployeeEndorsement::class)
            ? EmployeeEndorsement::pending()->count()
            : 0;
    }

    private function recentDecisionsOnOwnLeave(User $user)
    {
        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        return LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED])
            ->where('hr_acted_at', '>=', now()->subDays(self::RECENT_DECISION_DAYS))
            ->latest('hr_acted_at')
            ->limit(5)
            ->get();
    }

    /** @return array<string, mixed> */
    private function summary(string $key, string $tone, string $title, string $detail, string $href): array
    {
        return [
            'key' => $key,
            'kind' => 'summary',
            'tone' => $tone,
            'title' => $title,
            'detail' => $detail,
            'href' => $href,
            'at' => null,
        ];
    }
}
