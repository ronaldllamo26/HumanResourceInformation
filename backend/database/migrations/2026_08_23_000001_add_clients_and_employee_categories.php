<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PrimePower is a manpower agency, not a single employer.
 *
 * The schema modelled one workforce in six departments. In reality there are
 * two kinds of employee and they behave differently in almost every module:
 *
 * - **Internal staff** run the agency itself — HR, admin, accounting. They are
 *   filed against a department, exactly as before.
 * - **External employees** are deployed to a client company. They are still
 *   PrimePower's employees — PrimePower pays them and files their
 *   contributions — but the client is who they report to, who is billed for
 *   them, and who payroll has to be separable by.
 *
 * Deployment is a single `client_id` rather than a dated history: a move
 * between clients rewrites which client a past payslip is grouped under. That
 * is a known trade-off, taken deliberately for a workforce that does not move
 * often; the upgrade path is a `deployments` table with start/end dates and a
 * `clientAsOf()` read, the same shape as SalaryAdjustmentService::rateAsOf().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('industry')->nullable();

            // Where the deployment sits. Philippine minimum wage is set per
            // region by that region's RTWPB, so this is not decoration — it
            // decides which wage floor a deployed employee is measured
            // against. Held on the client because a client site is in one
            // place; an employee posted somewhere else overrides it on their
            // own record.
            $table->string('wage_region', 16)->nullable();

            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_number', 32)->nullable();
            $table->string('address')->nullable();

            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            // internal | external. Defaulted rather than nullable: every
            // employee is one or the other, and a null here would quietly
            // drop people out of both lists.
            $table->string('employment_category', 16)
                ->default('internal')
                ->after('employee_number');

            // Null for internal staff — they are deployed nowhere.
            $table->foreignId('client_id')
                ->nullable()
                ->after('department_id')
                ->constrained()
                ->nullOnDelete();

            // Overrides the client's region for someone posted away from the
            // client's own site. Null means "use the client's".
            $table->string('wage_region', 16)->nullable()->after('pay_frequency');
        });

        // Both are filtered on constantly — the directory, payroll grouping,
        // and every per-client report. Postgres does not index a foreign key
        // column on its own; see 2026_08_11_000001_index_foreign_keys.
        Schema::table('employees', function (Blueprint $table) {
            $table->index('client_id');
            $table->index('employment_category');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropIndex(['employment_category']);
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['employment_category', 'wage_region']);
        });

        Schema::dropIfExists('clients');
    }
};
