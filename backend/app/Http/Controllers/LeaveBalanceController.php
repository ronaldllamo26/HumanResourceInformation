<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\EmployeeService;
use App\Services\LeaveAccrualService;
use App\Services\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 3 — leave credit balances, one row per employee per year.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly EmployeeService $employees,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $year = (int) $request->query('year', now()->year);
        $types = LeaveType::where('is_active', true)->orderBy('code')->get();

        $employees = $this->employees->scopedQuery($request->user())
            ->orderBy('last_name')
            ->get(['employees.id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix']);

        $balances = LeaveBalance::whereIn('employee_id', $employees->pluck('id'))
            ->where('year', $year)
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('HR/Leave/Balances', [
            'year' => $year,
            'years' => range(now()->year - 3, now()->year + 1),
            'types' => $types->map(fn (LeaveType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'is_paid' => $type->is_paid,
            ]),
            'rows' => $employees->map(function (Employee $employee) use ($balances, $types) {
                $owned = $balances->get($employee->id, collect())->keyBy('leave_type_id');

                return [
                    'employee_id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'credits' => $types->mapWithKeys(function (LeaveType $type) use ($owned) {
                        $balance = $owned->get($type->id);

                        return [$type->id => [
                            'earned' => (float) ($balance?->credits_earned ?? 0),
                            'used' => (float) ($balance?->credits_used ?? 0),
                            'carried_over' => (float) ($balance?->credits_carried_over ?? 0),
                            'available' => $balance?->available() ?? 0.0,
                        ]];
                    }),
                ];
            })->values(),
            'can' => ['adjust' => $request->user()->can('adjustBalances', LeaveRequest::class)],
        ]);
    }

    /** HR sets the opening credits for a year — the annual leave allocation. */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('adjustBalances', LeaveRequest::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'credits_earned' => ['required', 'numeric', 'min:0', 'max:400'],
            'credits_carried_over' => ['required', 'numeric', 'min:0', 'max:400'],
        ]);

        /*
         * Segregation of duties: HR decides everybody's leave, so HR must not
         * also be able to top up its own credits — that would be approving
         * leave against a balance you wrote yourself. Another HR user or an
         * admin makes the change.
         */
        $employee = Employee::findOrFail($validated['employee_id']);

        if ($employee->user_id !== null && $employee->user_id === $request->user()->id) {
            return back()->with('error', 'You cannot adjust your own leave credits. Ask another HR user or an administrator.');
        }

        /*
         * No balance may go below what has already been taken. Setting earned
         * credits under the days already used would leave a negative balance
         * — leave that was approved and paid, now owed back with nobody having
         * decided that.
         */
        $existing = LeaveBalance::where([
            'employee_id' => $validated['employee_id'],
            'leave_type_id' => $validated['leave_type_id'],
            'year' => $validated['year'],
        ])->first();

        $used = (float) ($existing?->credits_used ?? 0);

        if ((float) $validated['credits_earned'] + (float) $validated['credits_carried_over'] < $used) {
            throw ValidationException::withMessages([
                'credits_earned' => "{$used} day(s) are already used this year, so earned plus carried-over credits cannot be lower than that.",
            ]);
        }

        LeaveBalance::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'leave_type_id' => $validated['leave_type_id'],
                'year' => $validated['year'],
            ],
            [
                'credits_earned' => $validated['credits_earned'],
                'credits_carried_over' => $validated['credits_carried_over'],
            ],
        );

        return back()->with('success', 'Leave credits updated.');
    }

    /**
     * Brings every balance up to what service has actually earned.
     *
     * This replaced a bulk allocation that handed every active employee a full
     * year's entitlement on 1 January — which meant someone hired in November
     * started with the same fifteen days as someone who had worked all year,
     * and could file all of them in December. Safe to re-run: it recomputes
     * from the hire date rather than adding, so running it twice grants
     * nothing twice.
     */
    public function accrue(Request $request, LeaveAccrualService $accrual): RedirectResponse
    {
        Gate::authorize('adjustBalances', LeaveRequest::class);

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $accrual->accrue($validated['year']);

        $message = "Accrued credits for {$validated['year']}: "
            ."{$result['updated']} line(s) updated, {$result['unchanged']} already current.";

        // Balances that had already been spent past what service earns are
        // held at what was used, not reduced — but HR should know.
        if ($result['over_granted'] !== []) {
            $count = count($result['over_granted']);

            return back()->with(
                'error',
                $message." {$count} balance(s) had already used more than accrual grants; "
                    .'those were left at the used figure rather than reduced.',
            );
        }

        return back()->with('success', $message);
    }
}
