<?php

namespace App\Console\Commands;

use App\Jobs\CleanupExpiredBackupsJob;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class CleanupExpiredBackups extends Command
{
    protected $signature = 'backup:cleanup {--dispatch : Dispatch as a background job instead of running inline}';

    protected $description = 'Delete expired database backups based on retention policy';

    public function handle(DatabaseBackupService $backupService): int
    {
        if ($this->option('dispatch')) {
            CleanupExpiredBackupsJob::dispatch();
            $this->info('Backup cleanup job dispatched.');

            return self::SUCCESS;
        }

        $this->info('Cleaning up expired backups...');

        $deleted = $backupService->cleanupExpired();
        $this->info("Cleanup completed: {$deleted} expired backup(s) removed.");

        return self::SUCCESS;
    }
}
