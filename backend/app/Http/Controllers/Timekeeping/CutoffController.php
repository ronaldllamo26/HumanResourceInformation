<?php

namespace App\Http\Controllers\Timekeeping;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCutoff;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\AttendanceCutoffService;
use App\Services\TimekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cutoff Closing — each payroll period's attendance, checked and frozen.
 *
 * The periods come from Payroll rather than a range of their own, so the
 * cutoff and the run that pays from it cannot cover different days.
 */
class CutoffController extends Controller
{
    public function __construct(
        private readonly AttendanceCutoffService $cutoffs,
        private readonly TimekeepingService $timekeeping,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('closeCutoff', AttendanceLog::class);

        $periods = PayrollPeriod::query()->orderByDesc('start_date')->limit(24)->get();
        $cutoffs = AttendanceCutoff::with('closer:id,name')->whereIn('payroll_period_id', $periods->pluck('id'))->get()->keyBy('payroll_period_id');

        return Inertia::render('HR/Timekeeping/Cutoffs', [
            'periods' => $periods->map(function (PayrollPeriod $period) use ($cutoffs) {
                $cutoff = $cutoffs[$period->id] ?? null;

                return [
                    'id' => $period->id,
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'pay_date' => $period->pay_date?->toDateString(),
                    'status' => $cutoff?->status ?? AttendanceCutoff::STATUS_OPEN,
                    'closed_by' => $cutoff?->closer?->name,
                    'closed_at' => $cutoff?->closed_at?->toDateTimeString(),
                    'outstanding' => $cutoff?->isClosed() ? null : array_sum($this->cutoffs->outstanding($period)),
                ];
            }),
        ]);
    }

    public function show(Request $request, PayrollPeriod $period): Response
    {
        Gate::authorize('closeCutoff', AttendanceLog::class);

        $cutoff = $this->cutoffs->forPeriod($period)->load(['closer:id,name', 'reopener:id,name']);
        [$from, $to] = $this->timekeeping->periodRange($period);

        $employees = Employee::query()
            ->where('status', '!=', 'inactive')
            ->with('client:id,name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'client_id', 'employment_category']);

        $summaries = $this->timekeeping->summaries($employees->pluck('id')->all(), $from, $to);

        return Inertia::render('HR/Timekeeping/CutoffShow', [
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
            ],
            'cutoff' => [
                'status' => $cutoff->status,
                'closed_by' => $cutoff->closer?->name,
                'closed_at' => $cutoff->closed_at?->toDateTimeString(),
                'reopened_by' => $cutoff->reopener?->name,
                'reopened_at' => $cutoff->reopened_at?->toDateTimeString(),
                'reopen_reason' => $cutoff->reopen_reason,
            ],
            'outstanding' => $this->cutoffs->outstanding($period),
            'rows' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'number' => $employee->employee_number,
                'assignment' => $employee->client?->name ?? 'Internal',
                ...$summaries[$employee->id],
                'hours_worked' => round($summaries[$employee->id]['minutes_worked'] / 60, 1),
            ]),
            'can' => [
                'close' => $request->user()->can('closeCutoff', AttendanceLog::class),
                'reopen' => $request->user()->can('reopenCutoff', AttendanceLog::class),
            ],
        ]);
    }

    public function close(Request $request, PayrollPeriod $period): RedirectResponse
    {
        Gate::authorize('closeCutoff', AttendanceLog::class);

        $this->cutoffs->close($period, $request->user());

        return back()->with('success', "{$period->name} is closed. Its time records can no longer change.");
    }

    public function reopen(Request $request, PayrollPeriod $period): RedirectResponse
    {
        Gate::authorize('reopenCutoff', AttendanceLog::class);

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        $this->cutoffs->reopen($period, $request->user(), $data['reason']);

        return back()->with('success', "{$period->name} is open again. Recompute payroll after the records are fixed.");
    }
}
