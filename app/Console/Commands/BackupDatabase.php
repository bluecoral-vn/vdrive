<?php

namespace App\Console\Commands;

use App\Jobs\RunDatabaseBackupJob;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:run {--dispatch : Dispatch as a background job instead of running inline}';

    protected $description = 'Create a database backup (dump, zip, upload to R2)';

    public function handle(DatabaseBackupService $backupService): int
    {
        if ($this->option('dispatch')) {
            RunDatabaseBackupJob::dispatch();
            $this->info('Database backup job dispatched.');

            return self::SUCCESS;
        }

        $this->info('Starting database backup...');

        try {
            $backup = $backupService->createBackup();
            $this->info("Backup completed: {$backup->file_path} ({$backup->file_size} bytes)");

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
