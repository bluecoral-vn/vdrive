<?php

namespace App\Services;

use App\Jobs\DeleteR2ObjectJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class BrandingService
{
    private const DEFAULT_APP_NAME = 'Blue Coral';

    private const DEFAULT_COPYRIGHT_TEXT = '© 2017–2026 Blue Coral. All rights reserved.';

    private const DEFAULT_TAG_LINE = 'Digital agency from Saigon';

    private const LOGO_ALLOWED_MIMES = ['image/png', 'image/svg+xml', 'image/webp'];

    private const FAVICON_ALLOWED_MIMES = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png'];

    private const MAX_FILE_SIZE = 1048576; // 1MB

    public function __construct(
        private SystemConfigService $configService,
        private R2ClientService $r2ClientService,
    ) {}

    /**
     * Get current branding configuration with presigned asset URLs.
     *
     * @return array{app_name: string, copyright_text: ?string, logo_url: ?string, favicon_url: ?string}
     */
    public function getBranding(): array
    {
        $logoPath = $this->configService->get('branding.logo_path');
        $faviconPath = $this->configService->get('branding.favicon_path');

        return [
            'app_name' => $this->configService->get('branding.app_name') ?? self::DEFAULT_APP_NAME,
            'copyright_text' => $this->configService->get('branding.copyright_text') ?? self::DEFAULT_COPYRIGHT_TEXT,
            'tag_line' => $this->configService->get('branding.tag_line') ?? self::DEFAULT_TAG_LINE,
            'logo_url' => $logoPath ? $this->generateAssetUrl($logoPath) : null,
            'favicon_url' => $faviconPath ? $this->generateAssetUrl($faviconPath) : null,
        ];
    }

    /**
     * Update branding text fields and optionally upload new logo/favicon.
     *
     * @return array{app_name: string, copyright_text: ?string, logo_url: ?string, favicon_url: ?string}
     */
    public function updateBranding(array $data, ?UploadedFile $logo = null, ?UploadedFile $favicon = null): array
    {
        if (array_key_exists('app_name', $data)) {
            $appName = $data['app_name'] !== null ? strip_tags(trim($data['app_name'])) : null;
            $this->configService->set('branding.app_name', $appName ?: null);
        }

        if (array_key_exists('copyright_text', $data)) {
            $copyrightText = $data['copyright_text'] !== null ? strip_tags(trim($data['copyright_text'])) : null;
            $this->configService->set('branding.copyright_text', $copyrightText ?: null);
        }

        if (array_key_exists('tag_line', $data)) {
            $tagLine = $data['tag_line'] !== null ? strip_tags(trim($data['tag_line'])) : null;
            $this->configService->set('branding.tag_line', $tagLine ?: null);
        }

        if ($logo !== null) {
            $this->validateMime($logo, self::LOGO_ALLOWED_MIMES, 'logo');
            $this->replaceAsset('branding.logo_path', $logo, 'logo');
        }

        if ($favicon !== null) {
            $this->validateMime($favicon, self::FAVICON_ALLOWED_MIMES, 'favicon');
            $this->replaceAsset('branding.favicon_path', $favicon, 'favicon');
        }

        return $this->getBranding();
    }

    /**
     * Delete a branding asset (logo or favicon) and restore to default.
     */
    public function deleteAsset(string $type): void
    {
        $key = match ($type) {
            'logo' => 'branding.logo_path',
            'favicon' => 'branding.favicon_path',
            default => throw new \InvalidArgumentException("Invalid branding asset type: {$type}"),
        };

        $oldPath = $this->configService->get($key);

        if ($oldPath) {
            DeleteR2ObjectJob::dispatch($oldPath);
        }

        $this->configService->set($key, null);
    }

    /**
     * Validate uploaded file's actual MIME type using finfo.
     */
    private function validateMime(UploadedFile $file, array $allowedMimes, string $fieldName): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($file->getRealPath());

        if (! in_array($actualMime, $allowedMimes, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $fieldName => ["The {$fieldName} file type is not allowed. Detected: {$actualMime}"],
            ]);
        }
    }

    /**
     * Upload a new asset to R2 and delete the old one.
     */
    private function replaceAsset(string $configKey, UploadedFile $file, string $type): void
    {
        // Delete old asset
        $oldPath = $this->configService->get($configKey);
        if ($oldPath) {
            DeleteR2ObjectJob::dispatch($oldPath);
        }

        // Upload new asset
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $objectKey = sprintf('branding/%s_%s.%s', $type, Str::uuid()->toString(), $extension);

        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'Body' => fopen($file->getRealPath(), 'r'),
            'ContentType' => $file->getMimeType(),
        ]);

        $this->configService->set($configKey, $objectKey);
    }

    /**
     * Generate a presigned URL for a branding asset (1-hour expiry).
     */
    private function generateAssetUrl(string $objectKey): ?string
    {
        try {
            $s3 = $this->r2ClientService->client();
            $command = $s3->getCommand('GetObject', [
                'Bucket' => $this->r2ClientService->bucket(),
                'Key' => $objectKey,
            ]);

            $presigned = $s3->createPresignedRequest($command, '+1 hour');

            return (string) $presigned->getUri();
        } catch (\Throwable) {
            return null;
        }
    }
}
