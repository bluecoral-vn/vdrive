<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        $appName = EmailLayoutHelper::branding()['app_name'];

        return new Envelope(
            subject: "Reset Your Password — {$appName}",
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
        $escapedName = e($this->recipientName);

        $bodyHtml = <<<HTML
        <h2 style="margin:0 0 8px; color:#171717; font-size:16px; font-weight:600;">Password Reset Request</h2>
        <p style="margin:0 0 16px; color:#525252; font-size:14px; line-height:1.6;">
            Hi <strong>{$escapedName}</strong>, we received a request to reset your password.
        </p>
        <p style="margin:0 0 24px; color:#525252; font-size:14px; line-height:1.6;">
            Click the button below to set a new password. This link will expire in <strong>60 minutes</strong>.
        </p>
        <div style="text-align:center; margin-bottom:24px;">
            <a href="{$this->resetUrl}" style="display:inline-block; background-color:#171717; color:#ffffff; padding:10px 24px; border-radius:6px; text-decoration:none; font-size:14px; font-weight:500;">
                Reset Password
            </a>
        </div>
        <div style="background-color:#fafafa; border-left:3px solid #e5e5e5; padding:12px 16px; border-radius:4px; margin-bottom:24px;">
            <p style="margin:0; color:#525252; font-size:13px;">
                If you didn't request a password reset, you can safely ignore this email. Your password will not be changed.
            </p>
        </div>
        <p style="margin:0 0 4px; color:#a3a3a3; font-size:12px;">
            If the button doesn't work, copy and paste this URL into your browser:
        </p>
        <p style="margin:0; color:#525252; font-size:12px; word-break:break-all;">
            {$this->resetUrl}
        </p>
        HTML;

        return EmailLayoutHelper::wrap($bodyHtml);
    }
}
