<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Loans posted by **Core 3 (Benefits and Loans Management)**.
 *
 * The two systems own different halves of the same fact and neither can do the
 * other's. Core 3 approves a loan, sets its terms, and answers to the employee
 * for it. This system is the only one that can take money off a payslip — so
 * Core 3 posts the loan here, payroll amortises it, and the balance is read
 * back.
 *
 * The alternative was Core 3 keeping its own balance and telling us what to
 * deduct each period, which fails the first time a run is recomputed: the
 * deduction would be applied twice and the two balances would part company
 * with nobody watching. **Amortisation happens exactly once, at approval**, in
 * `PayrollService::amortiseLoans()`, which is the point of no return the whole
 * payroll module is built around.
 *
 * This is one of only two inbound doors into Core 2 — the other is the Core 1
 * endorsement queue. Everything else another system needs is read-only.
 */
class LoanController extends Controller
{
    /**
     * POST /api/v1/loans
     *
     * Idempotent on `reference_number`, for the same reason the endorsement
     * queue is idempotent on its reference: a timeout on Core 3's side is
     * indistinguishable from a failure, they will resend, and two rows for one
     * loan is the employee paying it twice.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $validated = $request->validate([
            /*
             * Core 3's own identifier, and what makes a retry safe. Required
             * rather than optional: without it there is no way to tell a
             * resend from a second loan, and the difference is money.
             */
            'reference_number' => ['required', 'string', 'max:64'],

            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(EmployeeLoan::TYPES)],
            'principal_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],

            /*
             * What comes off each payslip. Validated against the principal
             * because an amortisation larger than the loan is a keying error
             * that would otherwise take one period to surface, on somebody's
             * pay.
             */
            'monthly_amortization' => ['required', 'numeric', 'min:0.01', 'lte:principal_amount'],

            'start_date' => ['required', 'date'],
        ]);

        $existing = EmployeeLoan::where('reference_number', $validated['reference_number'])->first();

        if ($existing) {
            // 200, not 201: a resend is correct behaviour on their side, not a
            // fault to report. The row they already have comes back unchanged.
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $loan = EmployeeLoan::create([
            ...$validated,
            'outstanding_balance' => $validated['principal_amount'],
            'status' => EmployeeLoan::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $this->present($loan)], 201);
    }

    /**
     * GET /api/v1/loans/{reference}
     *
     * What is left to pay, read back by Core 3's own reference rather than by
     * our id — their reference is the only identifier they hold if our reply
     * to the POST never arrived, which is precisely when they need to ask.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $loan = EmployeeLoan::where('reference_number', $reference)->firstOrFail();

        return response()->json(['data' => $this->present($loan)]);
    }

    /**
     * GET /api/v1/employees/{employee}/loans
     *
     * Everything still being deducted from one person's pay — what a benefits
     * officer is asked for when an employee wants to know why their net is
     * short.
     */
    public function forEmployee(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $loans = $employee->loans()->latest('id')->get();

        return response()->json([
            'data' => $loans->map(fn (EmployeeLoan $loan) => $this->present($loan)),
            'meta' => [
                'employee_number' => $employee->employee_number,
                'total_outstanding' => round((float) $loans
                    ->where('status', EmployeeLoan::STATUS_ACTIVE)
                    ->sum('outstanding_balance'), 2),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(EmployeeLoan $loan): array
    {
        return [
            'reference_number' => $loan->reference_number,
            'employee_id' => $loan->employee_id,
            'type' => $loan->type,
            'principal_amount' => (float) $loan->principal_amount,
            'monthly_amortization' => (float) $loan->monthly_amortization,
            'outstanding_balance' => (float) $loan->outstanding_balance,
            'status' => $loan->status,
            'start_date' => $loan->start_date?->toDateString(),

            /*
             * Stated rather than left to be inferred from the status. A loan
             * only moves when a payroll run is *approved*, so a balance read
             * mid-cycle is the balance before this period's deduction — and a
             * consumer that assumed otherwise would show the employee a figure
             * that is about to change.
             */
            'amortised_on_payroll_approval' => true,
        ];
    }
}
