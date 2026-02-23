<?php

namespace App\Mail;

use App\Services\BrandingService;

/**
 * Shared email layout helper.
 *
 * Resolves branding values from BrandingService and wraps
 * email-specific body content in a consistent HTML shell
 * using a minimal shadcn/ui-inspired neutral palette.
 */
class EmailLayoutHelper
{
    /**
     * Get current branding values for use in emails.
     *
     * @return array{app_name: string, copyright_text: string, tag_line: string, logo_url: ?string}
     */
    public static function branding(): array
    {
        /** @var BrandingService $brandingService */
        $brandingService = app(BrandingService::class);
        $branding = $brandingService->getBranding();

        return [
            'app_name' => $branding['app_name'],
            'copyright_text' => $branding['copyright_text'] ?? '',
            'tag_line' => $branding['tag_line'] ?? '',
            'logo_url' => $branding['logo_url'] ?? null,
        ];
    }

    /**
     * Wrap body content HTML in the shared email layout.
     */
    public static function wrap(string $bodyHtml): string
    {
        $b = self::branding();
        $appName = e($b['app_name']);
        $tagLine = e($b['tag_line']);
        $copyright = e($b['copyright_text']);
        $logoUrl = $b['logo_url'];

        // Build header: logo (if available) + app name + tag line
        $logoBlock = '';
        if ($logoUrl) {
            $logoBlock = <<<HTML
            <img src="{$logoUrl}" alt="{$appName}" style="max-height:36px; max-width:180px; margin-bottom:8px;" />
            <br />
            HTML;
        }

        $tagLineBlock = '';
        if ($tagLine) {
            $tagLineBlock = <<<HTML
            <p style="margin:4px 0 0; color:#737373; font-size:13px; font-weight:400;">{$tagLine}</p>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        </head>
        <body style="margin:0; padding:0; background-color:#fafafa; font-family: ui-sans-serif, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#fafafa; padding:40px 0;">
                <tr>
                    <td align="center">
                        <table width="560" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e5e5e5; border-radius:8px; overflow:hidden;">
                            <!-- Header -->
                            <tr>
                                <td style="padding:32px 32px 24px; text-align:center; border-bottom:1px solid #e5e5e5;">
                                    {$logoBlock}
                                    <h1 style="margin:0; color:#171717; font-size:18px; font-weight:600; letter-spacing:-0.01em;">{$appName}</h1>
                                    {$tagLineBlock}
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style="padding:32px;">
                                    {$bodyHtml}
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="padding:20px 32px; border-top:1px solid #e5e5e5; text-align:center;">
                                    <p style="margin:0; color:#a3a3a3; font-size:12px;">{$copyright}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
