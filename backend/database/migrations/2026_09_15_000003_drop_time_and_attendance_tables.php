<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Time & Attendance was removed, data included, so it can be redesigned.
 *
 * The migrations that created these tables stay: they have already run on
 * every existing database, and deleting a migration that ran leaves the
 * `migrations` table describing a schema no file produces. `down()` does not
 * rebuild them — the rows are gone either way, and the redesigned submodules
 * will bring their own tables.
 *
 * Children before parents, so no foreign key refuses the drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendance_logs');
        Schema::dropIfExists('employee_schedules');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('shifts');
    }

    public function down(): void
    {
        //
    }
};
