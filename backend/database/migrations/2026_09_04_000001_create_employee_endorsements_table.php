<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People Core 1 has sent over, waiting on a decision here.
 *
 * Core 1 recruits; Core 2 employs. Between the two there is a handover, and
 * this table is it: a queue of proposed hires that HR accepts or declines
 * before anybody exists on this system's payroll.
 *
 * It is deliberately *not* the employees table with a status column. An
 * endorsement is a request from another system, not a person on the books —
 * it has no employee number, no salary, no contributions, and it may never
 * become any of those. Filing it as a half-built employee would put it in the
 * scope of every query that means "our workforce": headcount, payroll runs,
 * 201-file completeness, compliance filings. A rejected candidate would then
 * have to be excluded from each of those by hand, forever, and the first
 * query somebody forgot would be a stranger on a remittance.
 *
 * Identity columns are lifted out of the payload rather than read from it.
 * The inbox searches and sorts on the name, and filtering a JSON column after
 * `paginate()` corrupts the totals — the same trap the DTR history screen
 * documents. `payload` keeps everything Core 1 sent, verbatim, because what
 * that system chooses to send will grow and a column per field would have to
 * be migrated every time it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_endorsements', function (Blueprint $table) {
            $table->id();

            /*
             * Core 1's own identifier for this hire, and the reason a retry is
             * safe. A network timeout on their side is indistinguishable from
             * a failure, so they will send it again — and two rows for one
             * person is two approvals, two employee numbers, and two people on
             * the payroll who are one person.
             */
            $table->string('reference')->unique();

            // Which system sent it. One today; naming it now means a second
            // source does not need a migration to be told apart.
            $table->string('source', 32)->default('core1');

            // --- Identity, lifted out of the payload for the list screen ---
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 16)->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number', 32)->nullable();

            /*
             * What Core 1 hired them as, as free text. Deliberately not a
             * `position_id`: Core 1 does not know this system's positions
             * table, and matching a job title to a row is a judgement HR makes
             * on the form. Storing an id here would mean either inventing
             * master data from a string or refusing the endorsement over a
             * spelling — and refusing to *receive* somebody is the one outcome
             * this queue exists to avoid.
             */
            $table->string('position_title')->nullable();
            $table->string('client_name')->nullable();
            $table->date('date_hired')->nullable();

            // Everything Core 1 sent, unedited.
            $table->json('payload');

            $table->string('status', 16)->default('pending');

            // Set when an approval became a real record. Null on anything
            // still pending, and on everything rejected.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            /*
             * Why it was declined. Required on rejection, because Core 1 reads
             * this back — a recruiter told "rejected" with no reason sends the
             * same person again, and the queue fills with the same argument.
             */
            $table->text('decision_note')->nullable();

            $table->timestamps();

            // The inbox is "pending, newest first" nearly every time it loads.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_endorsements');
    }
};
