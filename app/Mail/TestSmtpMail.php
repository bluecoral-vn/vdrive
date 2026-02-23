<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestSmtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct() {}

    public function envelope(): Envelope
    {
        $appName = EmailLayoutHelper::branding()['app_name'];

        return new Envelope(
            subject: "SMTP Test — {$appName}",
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
        $appName = e(EmailLayoutHelper::branding()['app_name']);

        $bodyHtml = <<<HTML
        <h2 style="margin:0 0 8px; color:#171717; font-size:16px; font-weight:600;">SMTP Configuration Test</h2>
        <p style="margin:0 0 16px; color:#525252; font-size:14px; line-height:1.6;">
            This is a test email from <strong>{$appName}</strong>. If you're reading this, your SMTP configuration is working correctly.
        </p>
        <div style="background-color:#fafafa; border:1px solid #e5e5e5; border-radius:6px; padding:16px; text-align:center;">
            <p style="margin:0; color:#22c55e; font-size:14px; font-weight:500;">✓ SMTP is configured correctly</p>
        </div>
        HTML;

        return EmailLayoutHelper::wrap($bodyHtml);
    }
}
