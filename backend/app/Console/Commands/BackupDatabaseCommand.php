<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\DataAccessLogger;
use App\Services\DatabaseBackup;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Scheduled and manual database backup command.
 *
 * Automates daily full database backups to storage/app/backups/, logs the
 * backup event for audit compliance, and prunes dumps older than the retention
 * period.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'hris:backup {--retention=14 : Days to keep backups before pruning}';

    protected $description = 'Perform an automated database backup and prune stale backups';

    public function handle(DatabaseBackup $backupService, DataAccessLogger $logger): int
    {
        $this->info('Starting HRIS database backup...');

        $reason = $backupService->unavailableReason();
        if ($reason !== null) {
            $this->warn("Backup unavailable: {$reason}");
            Log::warning("Scheduled database backup skipped: {$reason}");

            return self::FAILURE;
        }

        $backupDir = storage_path('app/backups');
        if (! File::isDirectory($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        try {
            $tempPath = $backupService->dump();
            $filename = $backupService->filename();
            $destination = $backupDir.DIRECTORY_SEPARATOR.$filename;

            File::move($tempPath, $destination);
            $fileSize = File::size($destination);
            $sizeFormatted = round($fileSize / 1024 / 1024, 2).' MB';

            $logger->exported('database.backup', Setting::class, [
                'database' => config('database.connections.'.config('database.default').'.database'),
                'file' => $filename,
                'bytes' => $fileSize,
                'automated' => true,
            ]);

            $this->info("Backup successfully generated: {$filename} ({$sizeFormatted})");
            Log::info("Automated database backup completed: {$filename} ({$sizeFormatted})");

            $this->pruneStaleBackups($backupDir, (int) $this->option('retention'));

            return self::SUCCESS;
        } catch (ProcessFailedException $e) {
            $this->error('Database backup failed. Check application log for details.');
            Log::error('Scheduled database backup process failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function pruneStaleBackups(string $directory, int $retentionDays): void
    {
        $cutoff = Carbon::now()->subDays(max(1, $retentionDays));
        $files = File::files($directory);
        $pruned = 0;

        foreach ($files as $file) {
            if ($file->getExtension() === 'dump' && Carbon::createFromTimestamp($file->getMTime())->lt($cutoff)) {
                File::delete($file->getPathname());
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->line("Pruned {$pruned} backup(s) older than {$retentionDays} days.");
        }
    }
}
