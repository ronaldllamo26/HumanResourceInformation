<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application settings as a key/value store.
 *
 * A single wide row would need a migration for every new preference; this way a
 * new setting is a new key. Values are JSON so a setting can hold a scalar, a
 * list, or a nested object without changing shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // Namespaced, e.g. company.name / notifications.leave_filed.
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 48)->default('general')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
