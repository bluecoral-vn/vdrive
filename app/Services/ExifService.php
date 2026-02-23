<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Log;

class ExifService
{
    /**
     * Mime types that support EXIF extraction.
     */
    private const SUPPORTED_MIMES = [
        'image/jpeg',
        'image/heic',
    ];

    public function __construct(private R2ClientService $r2ClientService) {}

    /**
     * Check if a mime type supports EXIF extraction.
     */
    public function supportsExif(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIMES, true);
    }

    /**
     * Extract EXIF data from an image file and cache it in the database.
     *
     * Returns the extracted data or null on failure.
     * Never throws — failures are logged and null is returned.
     */
    public function extractAndCache(File $file): ?array
    {
        // Already cached
        if ($file->exif_data !== null) {
            return $file->exif_data;
        }

        if (! $this->supportsExif($file->mime_type)) {
            return null;
        }

        try {
            $raw = $this->fetchExifFromR2($file);

            if ($raw === null) {
                // Mark as attempted (empty array = no EXIF found)
                $file->update(['exif_data' => []]);

                return [];
            }

            $normalized = $this->normalize($raw);
            $file->update(['exif_data' => $normalized]);

            return $normalized;
        } catch (\Throwable $e) {
            Log::warning('EXIF extraction failed', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fetch file from R2, write to temp, extract EXIF, cleanup.
     *
     * @return array<string, mixed>|null
     */
    private function fetchExifFromR2(File $file): ?array
    {
        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $file->r2_object_key,
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'exif_');

        try {
            file_put_contents($tmpFile, (string) $result['Body']);

            if (! function_exists('exif_read_data')) {
                Log::warning('exif_read_data not available');

                return null;
            }

            $exif = @exif_read_data($tmpFile, 'IFD0,EXIF', true);

            return $exif !== false ? $exif : null;
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Normalize raw EXIF data into a clean, predictable structure.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $ifd0 = $raw['IFD0'] ?? [];
        $exif = $raw['EXIF'] ?? [];
        $computed = $raw['COMPUTED'] ?? [];

        return array_filter([
            'camera_make' => $ifd0['Make'] ?? null,
            'camera_model' => $ifd0['Model'] ?? null,
            'taken_at' => $this->parseDateTime($exif['DateTimeOriginal'] ?? $ifd0['DateTime'] ?? null),
            'width' => isset($computed['Width']) ? (int) $computed['Width'] : (isset($exif['ExifImageWidth']) ? (int) $exif['ExifImageWidth'] : null),
            'height' => isset($computed['Height']) ? (int) $computed['Height'] : (isset($exif['ExifImageLength']) ? (int) $exif['ExifImageLength'] : null),
            'iso' => isset($exif['ISOSpeedRatings']) ? (int) $exif['ISOSpeedRatings'] : null,
            'focal_length' => $this->parseFocalLength($exif['FocalLength'] ?? null),
            'exposure_time' => $exif['ExposureTime'] ?? null,
            'f_number' => $this->parseRational($exif['FNumber'] ?? null),
            'orientation' => isset($ifd0['Orientation']) ? (int) $ifd0['Orientation'] : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Parse EXIF datetime string to ISO 8601.
     */
    private function parseDateTime(?string $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }

        try {
            // EXIF format: "2025:03:10 08:12:00"
            $parsed = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', $dt);

            return $parsed !== false ? $parsed->format('c') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse focal length rational (e.g. "500/10") to string (e.g. "50mm").
     */
    private function parseFocalLength(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $mm = $this->parseRational($value);

        return $mm !== null ? "{$mm}mm" : null;
    }

    /**
     * Parse EXIF rational number string (e.g. "50/10") to float string.
     */
    private function parseRational(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$num, $den] = explode('/', $value, 2);
            $den = (float) $den;

            return $den > 0 ? (string) round((float) $num / $den, 2) : null;
        }

        return (string) $value;
    }
}
