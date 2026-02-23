<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class BackupFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $errorMessage,
        public readonly Carbon $attemptedAt,
    ) {}

    public function envelope(): Envelope
    {
        $b = EmailLayoutHelper::branding();

        return new Envelope(
            subject: "[{$b['app_name']}] Database Backup Failed",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $time = e($this->attemptedAt->toDateTimeString());
        $error = e($this->errorMessage);

        $body = <<<HTML
        <h2 style="margin:0 0 16px; color:#171717; font-size:16px; font-weight:600;">Database Backup Failed</h2>

        <p style="margin:0 0 12px; color:#404040; font-size:14px; line-height:1.6;">
            A scheduled database backup attempt has failed. Please review the error below and take corrective action.
        </p>

        <table style="width:100%; border-collapse:collapse; margin:16px 0;">
            <tr>
                <td style="padding:8px 12px; background-color:#fafafa; border:1px solid #e5e5e5; font-size:13px; color:#737373; width:120px;">Time</td>
                <td style="padding:8px 12px; border:1px solid #e5e5e5; font-size:13px; color:#171717;">{$time}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px; background-color:#fafafa; border:1px solid #e5e5e5; font-size:13px; color:#737373;">Error</td>
                <td style="padding:8px 12px; border:1px solid #e5e5e5; font-size:13px; color:#dc2626; font-family:monospace;">{$error}</td>
            </tr>
        </table>

        <p style="margin:16px 0 0; color:#737373; font-size:12px; line-height:1.5;">
            This notification is sent automatically when a backup fails. You will not receive another notification for the same issue within one hour.
        </p>
        HTML;

        return EmailLayoutHelper::wrap($body);
    }
}
