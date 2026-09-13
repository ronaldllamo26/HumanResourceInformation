<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 4 — recurring allowances and loan amortisations. Both feed straight
 * into the next payroll run.
 */
class CompensationController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): Response
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        return Inertia::render('HR/Payroll/Compensation', [
            'allowances' => EmployeeAllowance::with('employee:id,employee_number,first_name,middle_name,last_name,suffix')
                ->orderByDesc('effective_from')
                ->get()
                ->map(fn (EmployeeAllowance $allowance) => [
                    'id' => $allowance->id,
                    'employee' => $allowance->employee?->full_name,
                    'name' => $allowance->name,
                    'amount' => (float) $allowance->amount,
                    'frequency' => $allowance->frequency,
                    'is_taxable' => $allowance->is_taxable,
                    'effective_from' => $allowance->effective_from->toDateString(),
                    'effective_to' => $allowance->effective_to?->toDateString(),
                ]),

            'loans' => EmployeeLoan::with('employee:id,employee_number,first_name,middle_name,last_name,suffix')
                ->orderByDesc('start_date')
                ->get()
                ->map(fn (EmployeeLoan $loan) => [
                    'id' => $loan->id,
                    'employee' => $loan->employee?->full_name,
                    'type' => $loan->type,
                    'reference_number' => $loan->reference_number,
                    'principal_amount' => (float) $loan->principal_amount,
                    'monthly_amortization' => (float) $loan->monthly_amortization,
                    'outstanding_balance' => (float) $loan->outstanding_balance,
                    'status' => $loan->status,
                    'start_date' => $loan->start_date->toDateString(),
                ]),

            'employees' => $this->employees->scopedQuery($request->user())
                ->orderBy('last_name')
                ->get(['employees.id', 'first_name', 'middle_name', 'last_name', 'suffix'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                ]),

            'frequencies' => EmployeeAllowance::FREQUENCIES,
            'loanTypes' => EmployeeLoan::TYPES,
        ]);
    }

    public function storeAllowance(Request $request): RedirectResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        EmployeeAllowance::create($request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'frequency' => ['required', Rule::in(EmployeeAllowance::FREQUENCIES)],
            'is_taxable' => ['boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]) + ['is_taxable' => $request->boolean('is_taxable')]);

        return back()->with('success', 'Allowance added.');
    }

    public function destroyAllowance(EmployeeAllowance $allowance): RedirectResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $allowance->delete();

        return back()->with('success', 'Allowance removed.');
    }

    public function storeLoan(Request $request): RedirectResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(EmployeeLoan::TYPES)],
            'reference_number' => ['nullable', 'string', 'max:64'],
            'principal_amount' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'monthly_amortization' => ['required', 'numeric', 'min:1', 'lte:principal_amount'],
            'start_date' => ['required', 'date'],
        ], [
            'monthly_amortization.lte' => 'The amortisation cannot exceed the principal.',
        ]);

        EmployeeLoan::create([
            ...$validated,
            // A new loan starts with the full principal outstanding.
            'outstanding_balance' => $validated['principal_amount'],
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);

        return back()->with('success', 'Loan recorded.');
    }

    public function cancelLoan(EmployeeLoan $loan): RedirectResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $loan->update(['status' => EmployeeLoan::STATUS_CANCELLED]);

        return back()->with('success', 'Loan cancelled — it will no longer be deducted.');
    }
}
