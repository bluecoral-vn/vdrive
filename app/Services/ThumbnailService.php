<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use kornrunner\Blurhash\Blurhash;

class ThumbnailService
{
    /**
     * Mime types that support thumbnail generation.
     */
    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_THUMBNAIL_SIZE = 750;

    private const THUMBNAIL_QUALITY = 80;

    private const MAX_SOURCE_DIMENSION = 8000;

    private const BLURHASH_SAMPLE_SIZE = 32;

    private const BLURHASH_COMPONENTS_X = 4;

    private const BLURHASH_COMPONENTS_Y = 3;

    public function __construct(private R2ClientService $r2ClientService) {}

    /**
     * Check if a mime type supports thumbnail generation.
     */
    public function supportsThumbnail(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIMES, true);
    }

    /**
     * Generate thumbnail and blurhash for a file.
     *
     * Idempotent — skips if thumbnail already exists.
     * Never throws — failures are logged.
     */
    public function generateForFile(File $file): bool
    {
        // Already generated — idempotent
        if ($file->thumbnail_path !== null) {
            return true;
        }

        if (! $this->supportsThumbnail($file->mime_type)) {
            return false;
        }

        $tmpFile = null;
        $tmpThumb = null;

        try {
            $tmpFile = $this->downloadFromR2($file);

            // Validate image dimensions
            $imageInfo = @getimagesize($tmpFile);
            if ($imageInfo === false) {
                Log::warning('Thumbnail: invalid image file', ['file_id' => $file->id]);

                return false;
            }

            [$srcWidth, $srcHeight] = $imageInfo;
            if ($srcWidth > self::MAX_SOURCE_DIMENSION || $srcHeight > self::MAX_SOURCE_DIMENSION) {
                Log::warning('Thumbnail: image exceeds max dimension', [
                    'file_id' => $file->id,
                    'width' => $srcWidth,
                    'height' => $srcHeight,
                ]);

                return false;
            }

            // Generate thumbnail
            $manager = new ImageManager(new GdDriver);
            $image = $manager->read($tmpFile);

            // Scale down to fit 750x750 — no upscale
            $image->scaleDown(self::MAX_THUMBNAIL_SIZE, self::MAX_THUMBNAIL_SIZE);

            $thumbWidth = $image->width();
            $thumbHeight = $image->height();

            // Encode as WebP
            $tmpThumb = tempnam(sys_get_temp_dir(), 'thumb_');
            $encoded = $image->toWebp(self::THUMBNAIL_QUALITY);
            file_put_contents($tmpThumb, (string) $encoded);

            // Upload thumbnail to R2
            $thumbnailPath = "thumbnails/{$file->id}/750.webp";
            $this->uploadToR2($tmpThumb, $thumbnailPath);

            // Generate blurhash from small sample
            $blurhash = $this->generateBlurhash($image);

            // Update file record
            $file->update([
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_width' => $thumbWidth,
                'thumbnail_height' => $thumbHeight,
                'blurhash' => $blurhash,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Thumbnail generation failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if ($tmpFile !== null && file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if ($tmpThumb !== null && file_exists($tmpThumb)) {
                unlink($tmpThumb);
            }
        }
    }

    /**
     * Download file from R2 to a temp file.
     */
    private function downloadFromR2(File $file): string
    {
        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $file->r2_object_key,
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'r2img_');
        file_put_contents($tmpFile, (string) $result['Body']);

        return $tmpFile;
    }

    /**
     * Upload a file to R2.
     */
    private function uploadToR2(string $localPath, string $objectKey): void
    {
        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $objectKey,
            'Body' => fopen($localPath, 'r'),
            'ContentType' => 'image/webp',
        ]);
    }

    /**
     * Generate a blurhash string from an Intervention Image instance.
     *
     * Downscales to 32x32 for fast hash computation.
     */
    private function generateBlurhash(\Intervention\Image\Interfaces\ImageInterface $image): ?string
    {
        try {
            // Resize to small sample for blurhash
            $sample = clone $image;
            $sample->scale(self::BLURHASH_SAMPLE_SIZE, self::BLURHASH_SAMPLE_SIZE);

            $width = $sample->width();
            $height = $sample->height();

            // Extract pixel data
            $pixels = [];
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $color = $sample->pickColor($x, $y);
                    $row[] = [$color->red()->toInt(), $color->green()->toInt(), $color->blue()->toInt()];
                }
                $pixels[] = $row;
            }

            $hash = Blurhash::encode(
                $pixels,
                self::BLURHASH_COMPONENTS_X,
                self::BLURHASH_COMPONENTS_Y,
            );

            // Ensure hash fits within 50 chars
            return strlen($hash) <= 50 ? $hash : substr($hash, 0, 50);
        } catch (\Throwable $e) {
            Log::warning('Blurhash generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate a presigned URL for a thumbnail.
     */
    public function generateThumbnailUrl(File $file): ?string
    {
        if ($file->thumbnail_path === null) {
            return null;
        }

        $s3 = $this->r2ClientService->client();

        $command = $s3->getCommand('GetObject', [
            'Bucket' => $this->r2ClientService->bucket(),
            'Key' => $file->thumbnail_path,
            'ResponseContentType' => 'image/webp',
        ]);

        $presigned = $s3->createPresignedRequest($command, '+1 hour');

        return (string) $presigned->getUri();
    }
}
