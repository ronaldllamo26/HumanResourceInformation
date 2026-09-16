<?php

use App\Models\AuditLog;
use App\Services\AuditLogSigner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tamper-evidence signature on every audit row — see AuditLogSigner.
 *
 * Rows that already exist are signed here, as they stand today. That makes any
 * later change to them detectable; it cannot say whether they were changed
 * before this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('signature', 64)->nullable()->after('user_agent');
        });

        $signer = app(AuditLogSigner::class);

        AuditLog::query()->whereNull('signature')->orderBy('id')->chunkById(500, function ($logs) use ($signer) {
            foreach ($logs as $log) {
                $signer->signAndStore($log);
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
