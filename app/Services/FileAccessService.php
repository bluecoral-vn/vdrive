<?php

namespace App\Services;

use App\Models\File;

class FileAccessService
{
    public function __construct(private R2ClientService $r2ClientService) {}

    /**
     * Generate a presigned download URL (attachment disposition, 1h expiry).
     */
    public function generateDownloadUrl(File $file): string
    {
        $s3 = $this->r2ClientService->client();

        $command = $s3->getCommand('GetObject', [
            'Bucket' => $this->r2ClientService->bucket(),
            'Key' => $file->r2_object_key,
            'ResponseContentDisposition' => 'attachment; filename="'.$file->name.'"',
            'ResponseContentType' => $file->mime_type,
        ]);

        $presigned = $s3->createPresignedRequest($command, config('filesystems.signed_url_download_lifetime', '+2 hours'));

        return (string) $presigned->getUri();
    }

    /**
     * Generate a presigned preview URL (inline disposition, 10min expiry).
     * Browser-native rendering only — no external services.
     */
    public function generatePreviewUrl(File $file): string
    {
        $s3 = $this->r2ClientService->client();

        $command = $s3->getCommand('GetObject', [
            'Bucket' => $this->r2ClientService->bucket(),
            'Key' => $file->r2_object_key,
            'ResponseContentDisposition' => 'inline; filename="'.$file->name.'"',
            'ResponseContentType' => $file->mime_type,
        ]);

        $presigned = $s3->createPresignedRequest($command, config('filesystems.signed_url_preview_lifetime', '+10 minutes'));

        return (string) $presigned->getUri();
    }
}
