<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 — Performance Management.
 * `reviewer_type` supports self / supervisor / peer / subordinate (360 review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 24)->default('annual'); // quarterly | semi_annual | annual
            $table->date('period_start');
            $table->date('period_end');
            $table->date('review_due_date')->nullable();
            // draft | open | in_review | closed
            $table->string('status', 24)->default('draft');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            // Scope: global when both nullable, else per department/position.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 48)->nullable();  // e.g. Safety, Punctuality, Fuel Efficiency
            $table->string('measurement_unit', 32)->nullable();
            $table->decimal('default_weight', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight', 5, 2)->default(0);
            $table->decimal('target_value', 12, 2)->nullable();
            $table->decimal('actual_value', 12, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'kpi_id', 'review_cycle_id'], 'employee_kpis_unique');
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // self | supervisor | peer | subordinate
            $table->string('reviewer_type', 24)->default('supervisor');

            $table->decimal('overall_rating', 4, 2)->nullable(); // 1.00 – 5.00
            // draft | submitted | acknowledged
            $table->string('status', 24)->default('draft');

            $table->text('strengths')->nullable();
            $table->text('areas_for_improvement')->nullable();
            $table->text('goals')->nullable();
            $table->text('reviewer_comments')->nullable();
            $table->text('employee_comments')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['employee_id', 'review_cycle_id', 'reviewer_id', 'reviewer_type'],
                'performance_reviews_unique',
            );
            $table->index(['employee_id', 'status']);
        });

        Schema::create('performance_review_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained()->cascadeOnDelete();
            $table->decimal('rating', 4, 2);           // 1.00 – 5.00
            $table->decimal('weight', 5, 2)->default(0);
            $table->decimal('actual_value', 12, 2)->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['performance_review_id', 'kpi_id'], 'review_ratings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_review_ratings');
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('employee_kpis');
        Schema::dropIfExists('kpis');
        Schema::dropIfExists('review_cycles');
    }
};
