<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Separation;
use App\Services\SeparationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 4 — separation and final pay.
 *
 * Closes the lifecycle the rest of the system opens: hire, work, pay,
 * evaluate — and now leave.
 */
class SeparationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Separation::class);

        $filters = $request->only(['status', 'reason']);

        $separations = Separation::query()
            ->with('employee:id,employee_number,first_name,middle_name,last_name,suffix')
            ->filter($filters)
            ->latest('last_day')
            ->paginate(20)
            ->withQueryString();

        $deadlineDays = (int) config('separation.release_within_days', 30);

        return Inertia::render('HR/Payroll/Separations', [
            'separations' => [
                'data' => $separations->map(fn (Separation $separation) => [
                    'id' => $separation->id,
                    'employee' => [
                        'id' => $separation->employee?->id,
                        'full_name' => $separation->employee?->full_name,
                        'employee_number' => $separation->employee?->employee_number,
                    ],
                    'last_day' => $separation->last_day->toDateString(),
                    'reason' => $separation->reason,
                    'status' => $separation->status,
                    'net_final_pay' => (float) $separation->net_final_pay,
                    'released_at' => $separation->released_at?->toIso8601String(),
                    // Labor Advisory 06-20 puts release at 30 days. Counting
                    // it here is what turns a list into a work queue.
                    'days_to_deadline' => $separation->released_at
                        ? null
                        : (int) Carbon::today()->diffInDays(
                            $separation->last_day->copy()->addDays($deadlineDays), false,
                        ),
                ]),
                'meta' => [
                    'from' => $separations->firstItem(),
                    'to' => $separations->lastItem(),
                    'total' => $separations->total(),
                    'links' => $separations->linkCollection()->toArray(),
                ],
            ],
            'filters' => $filters,
            'reasons' => Separation::REASONS,
            'statuses' => [Separation::STATUS_DRAFT, Separation::STATUS_CLEARED, Separation::STATUS_RELEASED],
            'releaseWithinDays' => $deadlineDays,
            // Only employees who are still active can be separated.
            'employees' => Employee::where('status', '!=', 'inactive')
                ->whereDoesntHave('separations', fn ($query) => $query->where('status', '!=', Separation::STATUS_RELEASED))
                ->orderBy('last_name')
                ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'value' => $employee->id,
                    'label' => "{$employee->full_name} ({$employee->employee_number})",
                ]),
            'can' => [
                'create' => $request->user()->can('create', Separation::class),
                'release' => $request->user()->isAdmin(),
            ],
        ]);
    }

    public function show(Request $request, Separation $separation): Response
    {
        Gate::authorize('view', $separation);

        $separation->load('employee:id,employee_number,first_name,middle_name,last_name,suffix', 'processor:id,name');

        return Inertia::render('HR/Payroll/Separation', [
            'separation' => [
                'id' => $separation->id,
                'employee' => [
                    'id' => $separation->employee?->id,
                    'full_name' => $separation->employee?->full_name,
                    'employee_number' => $separation->employee?->employee_number,
                ],
                'last_day' => $separation->last_day->toDateString(),
                'reason' => $separation->reason,
                'status' => $separation->status,
                'unpaid_salary' => (float) $separation->unpaid_salary,
                'thirteenth_month' => (float) $separation->thirteenth_month,
                'leave_conversion' => (float) $separation->leave_conversion,
                'loan_deduction' => (float) $separation->loan_deduction,
                'other_deductions' => (float) $separation->other_deductions,
                'net_final_pay' => (float) $separation->net_final_pay,
                'breakdown' => $separation->breakdown ?? [],
                'clearance' => $separation->clearance ?? [],
                'remarks' => $separation->remarks,
                'released_at' => $separation->released_at?->toIso8601String(),
                'processed_by' => $separation->processor?->name,
                'is_cleared' => $separation->isCleared(),
            ],
            'can' => [
                'update' => $request->user()->can('update', $separation),
                'release' => $request->user()->can('release', $separation),
            ],
        ]);
    }

    public function store(Request $request, SeparationService $service): RedirectResponse
    {
        Gate::authorize('create', Separation::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'last_day' => ['required', 'date'],
            'reason' => ['required', Rule::in(Separation::REASONS)],
            'days_unpaid' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        if ($employee->separations()->where('status', '!=', Separation::STATUS_RELEASED)->exists()) {
            return back()->with('error', 'That employee already has an open separation.');
        }

        $separation = $service->open($employee, $validated, $request->user());

        return redirect()
            ->route('hr.payroll.separation', $separation)
            ->with('success', 'Separation opened and final pay computed.');
    }

    /** Recomputes a draft against current figures. */
    public function recompute(Request $request, Separation $separation, SeparationService $service): RedirectResponse
    {
        Gate::authorize('update', $separation);

        $validated = $request->validate([
            'days_unpaid' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'],
        ]);

        $service->recompute($separation, $validated);

        return back()->with('success', 'Final pay recomputed.');
    }

    public function clearance(Request $request, Separation $separation, SeparationService $service): RedirectResponse
    {
        Gate::authorize('update', $separation);

        $validated = $request->validate([
            'key' => ['required', 'string'],
            'cleared' => ['required', 'boolean'],
        ]);

        $service->toggleClearance($separation, $validated['key'], $validated['cleared']);

        return back();
    }

    public function release(Request $request, Separation $separation, SeparationService $service): RedirectResponse
    {
        Gate::authorize('release', $separation);

        $service->release($separation);

        return back()->with('success', 'Final pay released and the employee marked separated.');
    }
}
