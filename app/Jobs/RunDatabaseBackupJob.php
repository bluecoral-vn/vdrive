<?php

namespace App\Jobs;

use App\Mail\BackupFailedMail;
use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(DatabaseBackupService $backupService, SystemConfigService $configService): void
    {
        try {
            $backupService->createBackup();
        } catch (\Throwable $e) {
            Log::error("RunDatabaseBackupJob failed: {$e->getMessage()}");

            $this->sendFailureNotification($e, $configService);
        }
    }

    /**
     * Send failure notification email if configured.
     * Anti-spam: skip if a failure email was already sent in the last hour.
     */
    private function sendFailureNotification(\Throwable $error, SystemConfigService $configService): void
    {
        $email = $configService->get('backup_notification_email');

        if (! $email) {
            return;
        }

        // Anti-spam: check if a failure notification was sent recently
        $recentFailure = DatabaseBackup::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->where('id', '!=', DatabaseBackup::query()
                ->where('status', 'failed')
                ->latest()
                ->value('id') ?? '')
            ->exists();

        if ($recentFailure) {
            Log::info('Skipping backup failure notification — already sent within the last hour.');

            return;
        }

        try {
            $this->applySmtpConfig($configService);

            Mail::to($email)->send(new BackupFailedMail(
                errorMessage: $error->getMessage(),
                attemptedAt: now(),
            ));
        } catch (\Throwable $e) {
            Log::warning("Failed to send backup failure notification: {$e->getMessage()}");
        }
    }

    /**
     * Apply SMTP settings from SystemConfigService at runtime.
     */
    private function applySmtpConfig(SystemConfigService $configService): void
    {
        $mapping = [
            'smtp_host' => 'mail.mailers.smtp.host',
            'smtp_port' => 'mail.mailers.smtp.port',
            'smtp_username' => 'mail.mailers.smtp.username',
            'smtp_password' => 'mail.mailers.smtp.password',
            'smtp_encryption' => 'mail.mailers.smtp.scheme',
            'smtp_from_address' => 'mail.from.address',
            'smtp_from_name' => 'mail.from.name',
        ];

        foreach ($mapping as $configKey => $laravelKey) {
            $value = $configService->resolve($configKey);
            if ($value && $value !== 'null') {
                $setVal = $configKey === 'smtp_port' ? (int) $value : $value;
                Config::set($laravelKey, $setVal);
            }
        }

        $host = $configService->resolve('smtp_host');
        if ($host) {
            Config::set('mail.default', 'smtp');
        }
    }
}
