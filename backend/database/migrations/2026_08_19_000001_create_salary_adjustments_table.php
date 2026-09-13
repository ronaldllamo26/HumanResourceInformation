<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an employee's rate came to be what it is.
 *
 * `employees.basic_salary` was a single mutable figure: a raise overwrote it
 * and the old rate was simply gone, along with when it changed and why. This
 * table is the history behind that number — every rate the employee has been
 * on, the date it took effect, and who approved it.
 *
 * The row is the record of a *decision*, so `previous_salary` is stored rather
 * than derived. Reading it back from the preceding row would re-narrate
 * history from whatever rows survive; a settlement or an audit needs the two
 * figures that were actually on the paper that was signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->decimal('previous_salary', 12, 2)->default(0);
            $table->decimal('new_salary', 12, 2);

            // The date the rate applies from — not the date it was encoded.
            // Payroll reads the rate in force over the period it is paying,
            // so a raise keyed in late does not rewrite last month's run.
            $table->date('effective_date');

            // hiring | regularization | merit | promotion | market | correction
            $table->string('reason', 32);
            $table->text('remarks')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Every lookup is "this employee's rate as of a date", newest first.
            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_adjustments');
    }
};
