<?php

namespace App\Services;

use App\Models\File;

class FileContentService
{
    /**
     * Maximum file size for text content retrieval (default 1MB).
     */
    private int $maxBytes;

    /**
     * Mime types / prefixes allowed for text content retrieval.
     */
    private const ALLOWED_PREFIXES = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
    ];

    public function __construct(private R2ClientService $r2ClientService)
    {
        $this->maxBytes = (int) config('vdrive.content_max_bytes', 1_048_576);
    }

    /**
     * Check whether a mime type is allowed for text content retrieval.
     */
    public function isTextMime(string $mimeType): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch text content of a file from R2.
     *
     * @throws \RuntimeException if R2 fetch fails
     */
    public function getTextContent(File $file): string
    {
        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $file->r2_object_key,
        ]);

        $body = (string) $result['Body'];

        // Ensure valid UTF-8
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'auto');
        }

        return $body;
    }

    /**
     * Get the maximum allowed file size for content retrieval.
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }
}
