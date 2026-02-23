<?php

namespace Tests\Traits;

use Aws\S3\S3Client;

/**
 * Provides real R2/S3 client access and automatic object cleanup.
 *
 * Tracks all created S3 object keys and deletes them in tearDown().
 * Skips the test if R2 credentials are not configured.
 */
trait S3CleanupTrait
{
    /** @var list<string> Object keys created during this test */
    protected array $trackedObjectKeys = [];

    protected ?S3Client $s3Client = null;

    protected string $s3Bucket = '';

    /**
     * Boot the real R2 client. Call in setUp().
     * Marks test as skipped if credentials are missing.
     */
    protected function bootRealR2(): void
    {
        $endpoint = env('AWS_ENDPOINT');
        $key = env('AWS_ACCESS_KEY_ID');
        $secret = env('AWS_SECRET_ACCESS_KEY');
        $bucket = env('AWS_BUCKET');

        if (! $endpoint || ! $key || ! $secret || ! $bucket) {
            $this->markTestSkipped('R2/S3 credentials not configured in .env');
        }

        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);

        $this->s3Bucket = $bucket;
    }

    /**
     * Track an object key for cleanup.
     */
    protected function trackObject(string $key): void
    {
        $this->trackedObjectKeys[] = $key;
    }

    /**
     * Upload a small test object and track it.
     */
    protected function putTestObject(string $key, string $body = 'test-content'): void
    {
        $this->s3Client->putObject([
            'Bucket' => $this->s3Bucket,
            'Key' => $key,
            'Body' => $body,
        ]);
        $this->trackObject($key);
    }

    /**
     * Assert an S3 object exists.
     */
    protected function assertS3ObjectExists(string $key, string $message = ''): void
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->s3Bucket,
                'Key' => $key,
            ]);
            $this->assertTrue(true);
        } catch (\Throwable) {
            $this->fail($message ?: "S3 object [{$key}] should exist but does not.");
        }
    }

    /**
     * Assert an S3 object does NOT exist.
     */
    protected function assertS3ObjectNotExists(string $key, string $message = ''): void
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->s3Bucket,
                'Key' => $key,
            ]);
            $this->fail($message ?: "S3 object [{$key}] should not exist but does.");
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                $this->assertTrue(true);

                return;
            }
            throw $e;
        }
    }

    /**
     * Get the size of an S3 object in bytes.
     */
    protected function getS3ObjectSize(string $key): int
    {
        $result = $this->s3Client->headObject([
            'Bucket' => $this->s3Bucket,
            'Key' => $key,
        ]);

        return (int) $result['ContentLength'];
    }

    /**
     * Delete all tracked S3 objects. Called automatically in tearDown().
     */
    protected function cleanupS3Objects(): void
    {
        foreach ($this->trackedObjectKeys as $key) {
            try {
                $this->s3Client->deleteObject([
                    'Bucket' => $this->s3Bucket,
                    'Key' => $key,
                ]);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }
        $this->trackedObjectKeys = [];
    }

    /**
     * Auto-cleanup S3 objects after each test.
     */
    #[\PHPUnit\Framework\Attributes\After]
    protected function tearDownS3Cleanup(): void
    {
        $this->cleanupS3Objects();
    }
}
