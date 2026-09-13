<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the licence fields into line with what an LTO card actually prints.
 *
 * The record held one free-text box called `license_restriction_codes`, which
 * was both the retired scheme's name and unusable for deciding anything. A
 * current licence prints two separate panels:
 *
 *   - **DL Codes** (A, A1, B, B1, B2, C, D, BE, CE) — the vehicle classes the
 *     holder may drive. For a fleet operator this is the legal ceiling on what
 *     somebody may be put behind the wheel of.
 *   - **Conditions** (1–5) — corrective lenses, special equipment, customized
 *     vehicle only, daylight driving only, hearing aid. Condition 4 is a
 *     scheduling fact, not paperwork: that driver cannot lawfully take a night
 *     run.
 *
 * Renamed rather than added beside, because the old column held the same
 * thing under the wrong name and leaving both would invite filing one card
 * into two places.
 *
 * The verification columns are the honest half of "is this licence real".
 * LTO publishes no API an employer can call, so a person checks the LTMS
 * portal and their answer is recorded here with their name and the date — a
 * weaker claim than an API call, and a far stronger one than a green tick
 * nobody can account for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('license_restriction_codes', 'license_dl_codes');
        });

        Schema::table('employees', function (Blueprint $table) {
            // The 1–5 conditions, comma-separated. Short: five single digits
            // and their separators is the whole range.
            $table->string('license_conditions', 32)->nullable()->after('license_dl_codes');

            // --- The recorded LTMS check ---
            $table->timestamp('license_verified_at')->nullable()->after('license_expiry');
            $table->foreignId('license_verified_by')
                ->nullable()
                ->after('license_verified_at')
                ->constrained('users')
                ->nullOnDelete();

            /*
             * What the person saw. Free text on purpose: the portal shows a
             * status in its own words, and flattening that into a boolean
             * would lose the one detail worth keeping — "active", "suspended
             * until March", "no record found" are three different answers and
             * only one of them is good news.
             */
            $table->string('license_verification_note', 500)->nullable()->after('license_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_verified_by');
            $table->dropColumn([
                'license_conditions',
                'license_verified_at',
                'license_verification_note',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('license_dl_codes', 'license_restriction_codes');
        });
    }
};
