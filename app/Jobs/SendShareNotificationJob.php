<?php

namespace App\Jobs;

use App\Mail\ShareNotificationMail;
use App\Models\EmailLog;
use App\Models\Share;
use App\Models\User;
use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendShareNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly string $shareId,
        public readonly int $recipientId,
        public readonly string $resourceName,
        public readonly string $resourceType,
        public readonly ?string $notes = null,
    ) {}

    public function handle(SystemConfigService $configService): void
    {
        $share = Share::query()->find($this->shareId);
        if (! $share) {
            return;
        }

        $recipient = User::query()->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        $sharer = User::query()->find($share->shared_by);
        if (! $sharer) {
            return;
        }

        // Idempotent: skip if already sent successfully for this share
        $existingLog = EmailLog::query()
            ->where('share_id', $this->shareId)
            ->where('status', 'success')
            ->exists();

        if ($existingLog) {
            return;
        }

        // Apply runtime SMTP config from DB (falls back to .env)
        $this->applySmtpConfig($configService);

        // Create or find the email log entry
        $emailLog = EmailLog::query()->firstOrCreate(
            [
                'share_id' => $this->shareId,
                'recipient' => $recipient->email,
            ],
            [
                'subject' => "{$sharer->name} shared a ".ucfirst($this->resourceType)." with you — {$this->resourceName}",
                'status' => 'queued',
                'resource_type' => $this->resourceType,
                'resource_id' => $share->file_id ?? (string) $share->folder_id,
                'metadata' => [
                    'sharer_id' => $sharer->id,
                    'permission' => $share->permission,
                    'has_notes' => $this->notes !== null,
                ],
            ],
        );

        try {
            $mailable = new ShareNotificationMail(
                share: $share,
                sharer: $sharer,
                resourceName: $this->resourceName,
                resourceType: $this->resourceType,
                notes: $this->notes,
            );

            // Render HTML body before sending so we can store it
            $renderedBody = $mailable->render();

            Mail::to($recipient->email)->send($mailable);

            $emailLog->update([
                'status' => 'success',
                'body' => $renderedBody,
                'sent_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $emailLog->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Apply SMTP settings from SystemConfigService at runtime.
     * This allows admin-configured DB values to override .env defaults.
     */
    private function applySmtpConfig(SystemConfigService $configService): void
    {
        $host = $configService->resolve('smtp_host');
        $port = $configService->resolve('smtp_port');
        $username = $configService->resolve('smtp_username');
        $password = $configService->resolve('smtp_password');
        $encryption = $configService->resolve('smtp_encryption');
        $fromAddress = $configService->resolve('smtp_from_address');
        $fromName = $configService->resolve('smtp_from_name');

        if ($host) {
            Config::set('mail.mailers.smtp.host', $host);
        }
        if ($port) {
            Config::set('mail.mailers.smtp.port', (int) $port);
        }
        if ($username && $username !== 'null') {
            Config::set('mail.mailers.smtp.username', $username);
        }
        if ($password && $password !== 'null') {
            Config::set('mail.mailers.smtp.password', $password);
        }
        if ($encryption && $encryption !== 'null') {
            Config::set('mail.mailers.smtp.scheme', $encryption);
        }
        if ($fromAddress) {
            Config::set('mail.from.address', $fromAddress);
        }
        if ($fromName) {
            Config::set('mail.from.name', $fromName);
        }

        // Force mailer to smtp when SMTP config exists
        if ($host) {
            Config::set('mail.default', 'smtp');
        }
    }
}
