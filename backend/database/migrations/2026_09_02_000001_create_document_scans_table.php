<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the scanner proposed, and what a person did with it.
 *
 * The scanner's own `confidence` field is not what this measures, and cannot
 * be: asked across six documents it answered "high" every time, including on
 * the answers that were wrong. A dashboard built on a model's opinion of
 * itself reports nothing.
 *
 * This records the two things that can be compared — the proposal, and the
 * values that were actually saved — so accuracy is *measured* rather than
 * self-reported. Which fields HR keeps, which it corrects, and on which kinds
 * of document, is a fact about the system rather than a claim by it.
 *
 * A row is written when a scan runs, not when an upload succeeds. A scan that
 * was abandoned is a real outcome — usually the reading was bad enough to
 * start over — and dropping those would flatter every figure here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Set when the scan led to a filed document. Null means the scan
            // was run and the upload never completed — see the note above.
            $table->foreignId('employee_document_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();

            // Which model answered, so a change of driver is visible in the
            // figures rather than silently shifting them.
            $table->string('driver', 32);
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // The normalised reading, whole. Stored rather than picked apart
            // into columns because what is worth counting will change — the
            // type sources did, twice — and a JSON column absorbs that where
            // a migration per question would not.
            $table->json('proposed');

            // The values the document was actually filed with, and the split
            // of which fields survived. Null until the upload completes.
            $table->json('saved')->nullable();
            $table->json('accepted')->nullable();
            $table->json('corrected')->nullable();

            $table->timestamps();

            // The two questions the report asks: how did this type do, and
            // what has happened lately.
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
