<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which 201-file documents were filed by the scanner rather than by a person.
 *
 * The batch filer can now file a document nobody looked at, when every check
 * it has agrees. That is only defensible if it can be told apart afterwards:
 * "somebody typed this" and "the system decided this" are different claims
 * about the same row, and an audit that cannot separate them has to treat
 * every row as the weaker of the two.
 *
 * `uploaded_by` still names the person who fed the batch through — they
 * started it and they answer for it — so this is not a substitute for that
 * column. It records *how* the row was reached, not who reached it.
 *
 * Defaults false, so every document filed before this existed reads as what it
 * was: hand-filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->boolean('filed_automatically')->default(false)->after('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropColumn('filed_automatically');
        });
    }
};
