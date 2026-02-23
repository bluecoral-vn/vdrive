<?php

namespace App\Mail;

use App\Models\Share;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShareNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Share $share,
        public readonly User $sharer,
        public readonly string $resourceName,
        public readonly string $resourceType,
        public readonly ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = ucfirst($this->resourceType);
        $appName = EmailLayoutHelper::branding()['app_name'];

        return new Envelope(
            subject: "{$this->sharer->name} shared a {$typeLabel} with you — {$this->resourceName}",
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
        $typeLabel = ucfirst($this->resourceType);
        $permission = ucfirst($this->share->permission);
        $appUrl = config('app.url', 'http://localhost');
        $sharedWithMeUrl = $appUrl.'/dashboard/shared-with-me';

        $notesSection = '';
        if ($this->notes) {
            $escapedNotes = e($this->notes);
            $notesSection = <<<HTML
            <div style="background-color:#fafafa; border-left:3px solid #d4d4d4; padding:12px 16px; border-radius:4px; margin-bottom:24px;">
                <p style="margin:0; color:#525252; font-size:14px; font-style:italic;">"{$escapedNotes}"</p>
            </div>
            HTML;
        }

        $bodyHtml = <<<HTML
        <h2 style="margin:0 0 8px; color:#171717; font-size:16px; font-weight:600;">{$typeLabel} Shared With You</h2>
        <p style="margin:0 0 20px; color:#525252; font-size:14px; line-height:1.6;">
            <strong>{$this->sharer->name}</strong> has shared a {$this->resourceType} with you.
        </p>
        <table width="100%" cellpadding="10" cellspacing="0" style="border:1px solid #e5e5e5; border-radius:6px; font-size:14px; margin-bottom:24px; border-collapse:collapse;">
            <tr style="border-bottom:1px solid #e5e5e5;">
                <td style="color:#737373; width:120px; padding:10px 12px;">{$typeLabel} Name</td>
                <td style="color:#171717; font-weight:500; padding:10px 12px;">{$this->resourceName}</td>
            </tr>
            <tr>
                <td style="color:#737373; padding:10px 12px;">Permission</td>
                <td style="color:#171717; padding:10px 12px;">{$permission}</td>
            </tr>
        </table>
        {$notesSection}
        <div style="text-align:center;">
            <a href="{$sharedWithMeUrl}" style="display:inline-block; background-color:#171717; color:#ffffff; padding:10px 24px; border-radius:6px; text-decoration:none; font-size:14px; font-weight:500;">
                View Shared Items
            </a>
        </div>
        HTML;

        return EmailLayoutHelper::wrap($bodyHtml);
    }
}
