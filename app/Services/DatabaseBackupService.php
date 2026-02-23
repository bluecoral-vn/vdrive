<?php

namespace App\Services;

use App\Models\DatabaseBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use ZipArchive;

class DatabaseBackupService
{
    private const LOCK_KEY = 'database-backup-lock';

    private const LOCK_TTL = 600; // 10 minutes

    private const R2_PREFIX = 'system-backups/';

    public function __construct(
        private R2ClientService $r2,
        private SystemConfigService $configService,
    ) {}

    /**
     * Create a full database backup: dump → zip → upload R2 → record.
     *
     * @throws \RuntimeException if a backup is already running
     */
    public function createBackup(): DatabaseBackup
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (! $lock->get()) {
            throw new \RuntimeException('A backup is already running.');
        }

        $backup = DatabaseBackup::query()->create([
            'file_path' => '',
            'file_size' => 0,
            'status' => 'running',
        ]);

        $tempDump = null;
        $tempZip = null;

        try {
            // 1. Dump SQLite database
            $tempDump = $this->dumpDatabase();

            // 2. Create zip archive
            $timestamp = now()->format('Ymd_His');
            $zipName = "backup_{$timestamp}.zip";
            $tempZip = $this->createZip($tempDump, $zipName);

            // 3. Upload to R2
            $r2Key = self::R2_PREFIX.$zipName;
            $fileSize = filesize($tempZip);
            $this->uploadToR2($tempZip, $r2Key);

            // 4. Calculate expiry based on retention
            $expiredAt = $this->calculateExpiry();

            // 5. Update backup record
            $backup->update([
                'file_path' => $r2Key,
                'file_size' => $fileSize,
                'status' => 'success',
                'expired_at' => $expiredAt,
            ]);

            Log::info("Database backup completed: {$r2Key} ({$fileSize} bytes)");

            return $backup;
        } catch (\Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error("Database backup failed: {$e->getMessage()}");

            throw $e;
        } finally {
            // Always clean up temp files
            if ($tempDump && file_exists($tempDump)) {
                @unlink($tempDump);
            }
            if ($tempZip && file_exists($tempZip)) {
                @unlink($tempZip);
            }

            $lock->release();
        }
    }

    /**
     * Clean up expired backups from R2 and database.
     */
    public function cleanupExpired(): int
    {
        $expired = DatabaseBackup::query()
            ->where('status', 'success')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->get();

        $deleted = 0;

        foreach ($expired as $backup) {
            try {
                // Delete from R2
                if ($backup->file_path) {
                    $this->r2->client()->deleteObject([
                        'Bucket' => $this->r2->bucket(),
                        'Key' => $backup->file_path,
                    ]);
                }

                $backup->delete();
                $deleted++;
            } catch (\Throwable $e) {
                // Log but continue with other backups
                Log::warning("Failed to cleanup backup {$backup->id}: {$e->getMessage()}");
            }
        }

        Log::info("Backup cleanup completed: {$deleted} expired backup(s) removed.");

        return $deleted;
    }

    /**
     * Delete a specific backup by model.
     */
    public function deleteBackup(DatabaseBackup $backup): void
    {
        if ($backup->file_path && $backup->status === 'success') {
            try {
                $this->r2->client()->deleteObject([
                    'Bucket' => $this->r2->bucket(),
                    'Key' => $backup->file_path,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to delete backup file from R2: {$e->getMessage()}");
            }
        }

        $backup->delete();
    }

    /**
     * Generate a presigned download URL for a backup.
     */
    public function getDownloadUrl(DatabaseBackup $backup, int $expiryMinutes = 10): string
    {
        $cmd = $this->r2->client()->getCommand('GetObject', [
            'Bucket' => $this->r2->bucket(),
            'Key' => $backup->file_path,
        ]);

        $presigned = $this->r2->client()->createPresignedRequest($cmd, "+{$expiryMinutes} minutes");

        return (string) $presigned->getUri();
    }

    /**
     * Check if backup is enabled.
     */
    public function isEnabled(): bool
    {
        return filter_var(
            $this->configService->get('backup_enabled'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Check if backup should run at the current time based on schedule config.
     */
    public function shouldRunNow(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $scheduleType = $this->configService->get('backup_schedule_type');
        $backupTime = $this->configService->get('backup_time');

        if (! $scheduleType || ! $backupTime || $scheduleType === 'manual') {
            return false;
        }

        $now = now();
        $currentTime = $now->format('H:i');

        if ($currentTime !== $backupTime) {
            return false;
        }

        if ($scheduleType === 'daily') {
            return true;
        }

        if ($scheduleType === 'monthly') {
            $dayOfMonth = (int) $this->configService->get('backup_day_of_month');

            if ($dayOfMonth < 1) {
                return false;
            }

            // If the configured day doesn't exist this month, skip
            $daysInMonth = $now->daysInMonth;
            if ($dayOfMonth > $daysInMonth) {
                return false;
            }

            return $now->day === $dayOfMonth;
        }

        return false;
    }

    /**
     * Get current backup configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'backup_enabled' => $this->isEnabled(),
            'backup_schedule_type' => $this->configService->get('backup_schedule_type') ?? 'daily',
            'backup_time' => $this->configService->get('backup_time') ?? '02:00',
            'backup_day_of_month' => $this->configService->get('backup_day_of_month'),
            'backup_retention_days' => $this->configService->get('backup_retention_days'),
            'backup_keep_forever' => filter_var(
                $this->configService->get('backup_keep_forever'),
                FILTER_VALIDATE_BOOLEAN,
            ),
            'backup_notification_email' => $this->configService->get('backup_notification_email'),
        ];
    }

    /**
     * Dump SQLite database to a temp file using sqlite3 .backup command.
     */
    private function dumpDatabase(): string
    {
        $dbPath = config('database.connections.sqlite.database');

        if (! $dbPath || ! file_exists($dbPath)) {
            throw new \RuntimeException('SQLite database file not found.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'vdrive_backup_');

        $result = Process::timeout(300)->run(
            sprintf("sqlite3 %s '.backup %s'", escapeshellarg($dbPath), escapeshellarg($tempPath)),
        );

        if (! $result->successful()) {
            @unlink($tempPath);

            throw new \RuntimeException('Database dump failed: '.$result->errorOutput());
        }

        if (! file_exists($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);

            throw new \RuntimeException('Database dump produced empty file.');
        }

        return $tempPath;
    }

    /**
     * Create a zip archive containing the dump file.
     */
    private function createZip(string $dumpPath, string $zipName): string
    {
        $zipPath = sys_get_temp_dir().'/'.$zipName;

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new \RuntimeException("Failed to create zip archive: error code {$result}");
        }

        // Add the dump as "database.sqlite" inside the zip
        $zip->addFile($dumpPath, 'database.sqlite');
        $zip->close();

        if (! file_exists($zipPath) || filesize($zipPath) === 0) {
            throw new \RuntimeException('Zip archive creation failed.');
        }

        return $zipPath;
    }

    /**
     * Upload a file to R2.
     */
    private function uploadToR2(string $filePath, string $r2Key): void
    {
        $this->r2->client()->putObject([
            'Bucket' => $this->r2->bucket(),
            'Key' => $r2Key,
            'SourceFile' => $filePath,
            'ContentType' => 'application/zip',
        ]);
    }

    /**
     * Calculate backup expiry based on retention config.
     */
    private function calculateExpiry(): ?Carbon
    {
        $keepForever = filter_var(
            $this->configService->get('backup_keep_forever'),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($keepForever) {
            return null;
        }

        $retentionDays = (int) ($this->configService->get('backup_retention_days') ?? 7);

        return now()->addDays($retentionDays);
    }
}
