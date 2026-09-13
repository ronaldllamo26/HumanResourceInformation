<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollAdjustment;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * One-off amounts another system puts on a payslip.
 *
 * **Fleet** posts trip allowances and per diems; **Supply Chain** posts a
 * deduction when an employee is accountable for a damaged or lost item. Both
 * are the same act — an amount, a label, a cutoff, and the name of whoever
 * decided it — so they share one endpoint rather than two that would drift.
 *
 * **What this endpoint does not do is the important part: it never touches a
 * payslip.** It writes a row, and `PayrollService::gatherInputs()` sums those
 * rows when the run is computed. That is not a stylistic choice — a draft run
 * can be recomputed freely, so an endpoint that added ₱500 to a payslip when
 * it was *called* would add it again on the next recompute, and the two systems
 * would part company with nobody watching. CLAUDE.md already records this from
 * the loan design: a sender that keeps its own running balance and tells us
 * what to apply each period fails the first time a run is recomputed.
 *
 * The same reason `Api\LoanController` stores a loan rather than a deduction.
 */
class PayrollAdjustmentController extends Controller
{
    /**
     * POST /api/v1/payroll/adjustments
     *
     * Idempotent on `(source, reference)`. A resend returns the existing row
     * with **200** rather than 201, so the sender can tell a duplicate from a
     * fresh submission without either being an error — exactly the contract
     * `/endorsements` and `/loans` already offer, and for the same reason: a
     * timeout on their side is indistinguishable from a failure, so they
     * resend, and two rows for one trip allowance is money.
     */
    public function store(Request $request): JsonResponse
    {
        /*
         * The same gate that guards loans and salary adjustments. "May this
         * caller put money on a payslip" has one answer in this system and it
         * already lives on `manageCompensation`; a second ability would be a
         * second answer waiting to disagree with the first.
         */
        Gate::authorize('manageCompensation', PayrollRun::class);

        $validated = $request->validate([
            /*
             * The sending system's own identifier, and what makes a retry
             * safe. Required rather than optional: without it there is no way
             * to tell a resend from a second allowance, and the difference is
             * money on somebody's payslip.
             */
            'reference' => ['required', 'string', 'max:64'],
            'source' => ['required', Rule::in(PayrollAdjustment::SOURCES)],

            'employee_id' => ['required', 'exists:employees,id'],

            /*
             * Which cutoff this belongs to, and required.
             *
             * A trip allowance is earned in a fortnight and paid in that
             * fortnight. Left to "the next run that happens", a row missed by
             * one run pays out in the following one and a row never consumed
             * pays out forever.
             */
            'payroll_period_id' => ['required', 'exists:payroll_periods,id'],

            'kind' => ['required', Rule::in(PayrollAdjustment::KINDS)],
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],

            /*
             * Only meaningful on an earning, and the sender's to state. A trip
             * allowance may be taxable or a de minimis benefit that is not, and
             * the difference reaches the withholding tax — so assuming it here
             * would be this system deciding somebody else's tax treatment.
             */
            'is_taxable' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $existing = PayrollAdjustment::where('source', $validated['source'])
            ->where('reference', $validated['reference'])
            ->first();

        if ($existing !== null) {
            /*
             * The stored row is returned unchanged rather than updated to
             * match the resend. A reference already used names a fact this
             * system has acted on — it may already be on a computed payslip —
             * and quietly rewriting the amount behind it would move money
             * nobody asked to move. Correcting one is a new reference, or a
             * DELETE below.
             */
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $period = PayrollPeriod::findOrFail($validated['payroll_period_id']);

        /*
         * A closed period is refused, and 409 says which kind of refusal it
         * is: the period exists and is simply past the point where an amount
         * can still reach a payslip. Accepting it would write a row that
         * nothing will ever read — an allowance somebody was promised and
         * never paid, with no error anywhere to say so.
         */
        abort_if(
            $period->runs()->reportable()->exists(),
            409,
            'This period has an approved or paid run. An amount posted now would never reach a payslip — open a new period or correct the run.',
        );

        $adjustment = PayrollAdjustment::create([
            'employee_id' => $validated['employee_id'],
            'payroll_period_id' => $validated['payroll_period_id'],
            'source' => $validated['source'],
            'reference' => $validated['reference'],
            'kind' => $validated['kind'],
            'label' => $validated['label'],
            'amount' => $validated['amount'],
            'is_taxable' => $validated['is_taxable'] ?? true,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->present($adjustment)], 201);
    }

    /**
     * GET /api/v1/payroll/adjustments/{source}/{reference}
     *
     * So a sender that lost our response can ask what we hold rather than
     * resending and guessing from the status code.
     */
    public function show(Request $request, string $source, string $reference): JsonResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $adjustment = PayrollAdjustment::where('source', $source)
            ->where('reference', $reference)
            ->firstOrFail();

        return response()->json(['data' => $this->present($adjustment)]);
    }

    /**
     * DELETE /api/v1/payroll/adjustments/{source}/{reference}
     *
     * The way to take back an amount posted in error — and it is refused once
     * the period has a finalised run, for the same reason posting into one is.
     * Money that has already been paid is not withdrawn by deleting the row
     * that explained it; that is a correction on the next cutoff, which is a
     * new adjustment with the opposite `kind`.
     */
    public function destroy(Request $request, string $source, string $reference): JsonResponse
    {
        Gate::authorize('manageCompensation', PayrollRun::class);

        $adjustment = PayrollAdjustment::where('source', $source)
            ->where('reference', $reference)
            ->firstOrFail();

        abort_if(
            $adjustment->period?->runs()->reportable()->exists() ?? false,
            409,
            'This period has an approved or paid run. Post an opposite adjustment on the next period instead.',
        );

        $adjustment->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function present(PayrollAdjustment $adjustment): array
    {
        return [
            'source' => $adjustment->source,
            'reference' => $adjustment->reference,
            'employee_id' => $adjustment->employee_id,
            'payroll_period_id' => $adjustment->payroll_period_id,
            'kind' => $adjustment->kind,
            // As it will read on the payslip, so a sender can check the wording
            // rather than discovering it on somebody's payslip.
            'payslip_label' => $adjustment->sourceLabel().' — '.$adjustment->label,
            'amount' => (float) $adjustment->amount,
            'is_taxable' => (bool) $adjustment->is_taxable,
            'created_at' => $adjustment->created_at?->toIso8601String(),
        ];
    }
}
