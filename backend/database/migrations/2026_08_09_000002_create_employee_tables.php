<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1 — Employee Information Management (the 201 file).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 32)->unique();

            // Optional self-service login for this employee.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // --- Personal information ---
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix', 16)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('gender', 16)->nullable();        // male | female
            $table->string('civil_status', 24)->nullable();  // single | married | widowed | separated
            $table->string('nationality')->default('Filipino');
            $table->string('religion')->nullable();
            $table->string('blood_type', 8)->nullable();

            // --- Contact ---
            $table->string('email')->nullable();
            $table->string('mobile_number', 32)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('present_address')->nullable();
            $table->string('permanent_address')->nullable();

            // --- Emergency contact ---
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_relationship', 64)->nullable();
            $table->string('emergency_contact_number', 32)->nullable();

            // --- Government IDs ---
            $table->string('sss_number', 32)->nullable();
            $table->string('philhealth_number', 32)->nullable();
            $table->string('pagibig_number', 32)->nullable();
            $table->string('tin', 32)->nullable();

            // --- Employment details ---
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            // regular | probationary | contractual | project-based | resigned | terminated
            $table->string('employment_status', 32)->default('probationary');
            $table->string('employment_type', 32)->default('full_time'); // full_time | part_time
            $table->date('date_hired')->nullable();
            $table->date('date_regularized')->nullable();
            $table->date('date_separated')->nullable();
            $table->text('separation_reason')->nullable();

            // --- Compensation baseline (payroll reads from here) ---
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->string('pay_frequency', 24)->default('semi_monthly');
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();

            // --- Fleet-specific ---
            $table->string('drivers_license_number', 32)->nullable();
            $table->string('license_restriction_codes', 32)->nullable();
            $table->date('license_expiry')->nullable();

            $table->string('photo_path')->nullable();
            $table->string('status', 24)->default('active'); // active | inactive | on_leave
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('employment_status');
            $table->index('status');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // contract | resume | government_id | clearance | certificate | medical | other
            $table->string('type', 48)->default('other');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'type']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_employee_id']);
        });

        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employees');
    }
};
