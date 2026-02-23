<?php

namespace App\Jobs;

use App\Mail\PasswordResetMail;
use App\Models\EmailLog;
use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SendPasswordResetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly string $email,
        public readonly string $recipientName,
        public readonly string $resetUrl,
    ) {}

    public function handle(SystemConfigService $configService): void
    {
        // Idempotent: skip if already sent successfully for this email in recent window
        $existingLog = EmailLog::query()
            ->where('recipient', $this->email)
            ->where('resource_type', 'password_reset')
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subMinutes(60))
            ->exists();

        if ($existingLog) {
            return;
        }

        // Apply runtime SMTP config from DB (falls back to .env)
        $this->applySmtpConfig($configService);

        // Create or find the email log entry
        $emailLog = EmailLog::query()->create([
            'recipient' => $this->email,
            'subject' => 'Password Reset Request',
            'status' => 'queued',
            'resource_type' => 'password_reset',
            'resource_id' => null,
            'share_id' => null,
            'metadata' => [
                'type' => 'password_reset',
            ],
        ]);

        try {
            $mailable = new PasswordResetMail(
                resetUrl: $this->resetUrl,
                recipientName: $this->recipientName,
            );

            $renderedBody = $mailable->render();

            Mail::to($this->email)->send($mailable);

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
