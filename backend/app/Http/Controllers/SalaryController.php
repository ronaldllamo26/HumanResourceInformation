<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\SalaryAdjustment;
use App\Services\SalaryAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 4 — where an employee's rate is set.
 *
 * The salary was previously a bare field on the employee form: HR typed over
 * it and the old rate, the date it changed, and the reason were all gone.
 */
class SalaryController extends Controller
{
    public function __construct(private readonly SalaryAdjustmentService $salaries) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SalaryAdjustment::class);

        $filters = $request->only(['employee_id', 'reason']);
        $reasons = config('payroll.salary_adjustment_reasons', []);
        $today = Carbon::today();

        $adjustments = SalaryAdjustment::query()
            ->with([
                'employee:id,employee_number,first_name,middle_name,last_name,suffix,position_id',
                'employee.position:id,title,min_salary,max_salary',
                'approver:id,name',
            ])
            ->filter($filters)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('HR/Payroll/Salaries', [
            'adjustments' => [
                'data' => $adjustments->map(fn (SalaryAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'employee' => [
                        'id' => $adjustment->employee?->id,
                        'full_name' => $adjustment->employee?->full_name,
                        'employee_number' => $adjustment->employee?->employee_number,
                        'position' => $adjustment->employee?->position?->title,
                    ],
                    'previous_salary' => (float) $adjustment->previous_salary,
                    'new_salary' => (float) $adjustment->new_salary,
                    'difference' => $adjustment->difference,
                    'effective_date' => $adjustment->effective_date->toDateString(),
                    'is_scheduled' => $adjustment->isScheduled(),
                    'reason' => $adjustment->reason,
                    'reason_label' => $reasons[$adjustment->reason] ?? $adjustment->reason,
                    'remarks' => $adjustment->remarks,
                    'approved_by' => $adjustment->approver?->name,
                    // Outside the position's band is worth seeing, but it is
                    // not an error: HR pays outside a band deliberately often
                    // enough that blocking it would be wrong.
                    'outside_band' => $this->outsideBand($adjustment),
                    'can_delete' => $request->user()->can('delete', $adjustment),
                ]),
                'meta' => [
                    'from' => $adjustments->firstItem(),
                    'to' => $adjustments->lastItem(),
                    'total' => $adjustments->total(),
                    'links' => $adjustments->linkCollection()->toArray(),
                ],
            ],
            'filters' => $filters,
            'reasons' => collect($reasons)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'summary' => [
                'scheduled' => SalaryAdjustment::whereDate('effective_date', '>', $today)->count(),
                'this_year' => SalaryAdjustment::whereYear('effective_date', $today->year)->count(),
                'payroll' => (float) Employee::where('status', 'active')->sum('basic_salary'),
            ],
            'employees' => Employee::where('status', '!=', 'inactive')
                ->with('position:id,title,min_salary,max_salary')
                ->orderBy('last_name')
                ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'position_id', 'basic_salary'])
                ->map(fn (Employee $employee) => [
                    'value' => $employee->id,
                    'label' => "{$employee->full_name} ({$employee->employee_number})",
                    'current_salary' => (float) $employee->basic_salary,
                    'position' => $employee->position?->title,
                    'min_salary' => $employee->position?->min_salary ? (float) $employee->position->min_salary : null,
                    'max_salary' => $employee->position?->max_salary ? (float) $employee->position->max_salary : null,
                ]),
            'can' => [
                'create' => $request->user()->can('create', SalaryAdjustment::class),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', SalaryAdjustment::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'new_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(array_keys(config('payroll.salary_adjustment_reasons', [])))],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $this->salaries->record($employee, $validated, $request->user());

        return back()->with('success', 'Salary adjustment recorded.');
    }

    public function destroy(Request $request, SalaryAdjustment $salaryAdjustment): RedirectResponse
    {
        Gate::authorize('delete', $salaryAdjustment);

        $this->salaries->delete($salaryAdjustment);

        return back()->with('success', 'Adjustment removed and the rate re-pointed at the one before it.');
    }

    /** Null when the position carries no band to compare against. */
    private function outsideBand(SalaryAdjustment $adjustment): ?string
    {
        $position = $adjustment->employee?->position;
        $salary = (float) $adjustment->new_salary;

        if (! $position) {
            return null;
        }

        if ($position->min_salary !== null && $salary < (float) $position->min_salary) {
            return 'below';
        }

        if ($position->max_salary !== null && $salary > (float) $position->max_salary) {
            return 'above';
        }

        return null;
    }
}
