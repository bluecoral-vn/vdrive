<?php

namespace App\Services;

use Aws\S3\S3Client;

class R2ClientService
{
    public function __construct(private SystemConfigService $configService) {}

    /**
     * Build an S3Client configured for Cloudflare R2.
     * Reads credentials from DB first, then .env fallback.
     */
    public function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->configService->resolve('r2_region') ?? config('filesystems.disks.r2.region', 'auto'),
            'endpoint' => $this->configService->resolve('r2_endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->configService->resolve('r2_access_key'),
                'secret' => $this->configService->resolve('r2_secret_key'),
            ],
        ]);
    }

    /**
     * Get the configured bucket name.
     */
    public function bucket(): string
    {
        return $this->configService->resolve('r2_bucket') ?? 'default';
    }
}
