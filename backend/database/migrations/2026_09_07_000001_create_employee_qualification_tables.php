<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1 — educational and qualification records on the 201 file.
 *
 * Three tables rather than one with a `type` column, because the three things
 * are only alike in belonging to a person: a school has a course and a year, a
 * training has a provider and an expiry, a skill has a level and neither. One
 * table would be eleven mostly-null columns and a validation rule per type
 * pretending to be a schema.
 *
 * The papers themselves — a diploma, a transcript, a TESDA certificate — stay
 * in `employee_documents`, where the scanner reads them and the credential
 * screen watches them lapse. These rows record the *fact*; the document is the
 * evidence, and filing the fact twice would be two answers to one question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // elementary | high_school | senior_high | vocational | college |
            // post_graduate — ordered in config/qualifications.php, which is
            // also how "highest attainment" is derived rather than stored. A
            // stored flag would need maintaining every time a row is added.
            $table->string('level', 32);
            $table->string('school');
            $table->string('course')->nullable();     // blank below college
            $table->year('year_graduated')->nullable();
            $table->string('honors')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });

        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('provider')->nullable();       // TESDA, LTO, in-house
            $table->string('reference_number', 64)->nullable();
            $table->date('completed_at')->nullable();
            // Some qualifications lapse — a TESDA NC, a first-aid card — and
            // some never do. Nullable says "does not expire" rather than
            // "nobody typed it", the same distinction the scanner draws.
            $table->date('expires_at')->nullable();
            $table->unsignedSmallInteger('hours')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('employee_id');
            $table->index('expires_at');
        });

        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // basic | intermediate | advanced — optional, because "can drive a
            // forklift" is worth recording before anyone has graded it.
            $table->string('proficiency', 24)->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('employee_trainings');
        Schema::dropIfExists('employee_educations');
    }
};
