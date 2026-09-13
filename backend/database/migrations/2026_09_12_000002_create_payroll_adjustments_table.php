<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off amounts another system puts on a payslip: Fleet's trip allowances,
 * Supply Chain's damage deductions.
 *
 * **These are stored facts that payroll *reads*, never events that mutate a
 * payslip — and that distinction is the whole safety property of this table.**
 * A draft run can be recomputed freely, which is the one thing that makes an
 * external amount dangerous: an endpoint that added ₱500 to a payslip when it
 * was called would add it again on the next recompute, and the two systems
 * would part company with nobody watching. `PayrollService::gatherInputs()`
 * sums these rows at compute time instead, so recomputing re-reads the same
 * rows and reaches the same total. Exactly the shape `employee_loans` already
 * takes, and for the same reason.
 *
 * The alternative that was rejected: letting Fleet post a *delta* ("add 500 to
 * this payslip"). CLAUDE.md already records why, from the loan design — a
 * sender that keeps its own running balance and tells us what to apply each
 * period fails the first time a run is recomputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            /*
             * Pinned to one cutoff, and required.
             *
             * A trip allowance is earned in a fortnight and paid in that
             * fortnight. Without the period it would have to be "the next run
             * that happens", which means a row that is missed by a run pays out
             * in the following one, and a row that is never consumed pays out
             * forever. Naming the period makes the row answerable: it belongs
             * to that payslip or to none.
             */
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();

            // Which system put it here. On the payslip line and in the audit,
            // so "who decided this" is answerable without a query.
            $table->string('source', 32);

            /*
             * The sending system's own identifier, and what makes a retry safe.
             * A timeout on their side is indistinguishable from a failure, so
             * they resend — and two rows for one trip allowance is money.
             * Unique per source rather than globally: Fleet's `TRIP-001` and
             * Supply Chain's `TRIP-001` are different facts.
             */
            $table->string('reference', 64);

            // `earning` adds to allowances; `deduction` lands in
            // `other_deductions`. Both slots already existed in
            // PayrollCalculator — the deduction one was built and never fed.
            $table->string('kind', 16);

            $table->string('label');
            $table->decimal('amount', 12, 2);

            /*
             * Only meaningful on an earning. A trip allowance may be taxable or
             * a de minimis benefit that is not, and the difference reaches the
             * withholding tax — so it is the sender's to state rather than
             * ours to assume.
             */
            $table->boolean('is_taxable')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['source', 'reference']);

            // The lookup `gatherInputs()` makes once per employee per run.
            $table->index(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
