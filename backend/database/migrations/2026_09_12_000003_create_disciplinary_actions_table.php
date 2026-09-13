<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disciplinary actions Core 4 records: warnings, and suspensions with dates.
 *
 * **This table is read, reported, and never enforced — which is the whole
 * design and the reason it is a table rather than a write into the DTR.**
 *
 * The obvious build was to let Core 4 post a suspension and have this system
 * mark those days absent. That was rejected: **a DTR another system can write
 * is not a record of anything.** The same argument already keeps employees out
 * of `attendance_logs` — they file a correction and somebody decides, because
 * a time record somebody can rewrite proves nothing at cut-off. Core 4 is
 * another system, and is no more entitled to it than an employee is.
 *
 * So a suspension lands here as a **stated fact**, and
 * `PayrollReadinessChecker` raises it as a **warning** before the money is
 * computed: "this person is suspended unpaid over four days of this cutoff and
 * their DTR shows nothing of the sort." HR then keys it, or decides not to.
 * That is the same bargain wage floors, salary bands, `PayrollReadiness` and
 * the document scanner all make — flag, never enforce.
 *
 * The cost, stated plainly: **an unpaid suspension nobody acts on is paid.**
 * That is a real gap and it is the deliberate one — the alternative is a
 * payroll that quietly docks somebody on another system's say-so, with no
 * decision and no decider recorded anywhere in this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Which system recorded it. Core 4 owns safety and discipline; HR
            // records one directly often enough that both are allowed.
            $table->string('source', 32)->default('core4');

            /*
             * The sending system's own identifier, and what makes a retry
             * safe — the same contract `/endorsements`, `/loans` and
             * `/payroll/adjustments` offer. Two rows for one suspension is two
             * warnings on the payroll screen for one event, which is how a
             * screen stops being read.
             */
            $table->string('reference', 64);

            $table->string('type', 32);
            $table->text('reason');

            /*
             * A warning has one date; a suspension has two. `effective_to` is
             * nullable for that reason rather than defaulted to the start — a
             * suspension of unknown length is a real state (pending
             * investigation) and writing a one-day end date would be inventing
             * the outcome.
             */
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            /*
             * Whether the suspension is without pay. Only this flag makes the
             * row reach payroll at all: a suspension *with* pay changes nothing
             * about a payslip, and raising it on the readiness panel would be
             * noise on a screen whose whole value is that every line needs
             * acting on.
             */
            $table->boolean('is_unpaid')->default(true);

            // Whoever signed it off on Core 4's side, as free text: their
            // people are not in this system's `users` table.
            $table->string('issued_by')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['source', 'reference']);

            // The lookup the readiness check makes once per run: every action
            // overlapping a cutoff.
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_actions');
    }
};
