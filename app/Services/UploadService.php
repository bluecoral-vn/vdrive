<?php

namespace App\Services;

use App\Jobs\ExtractExifJob;
use App\Jobs\GenerateThumbnailAndBlurhashJob;
use App\Models\File;
use App\Models\UploadSession;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UploadService
{
    private S3Client $s3;

    private string $bucket;

    public function __construct(
        private R2ClientService $r2ClientService,
        private QuotaService $quotaService,
        private SyncEventService $syncEventService,
    ) {
        $this->s3 = $this->r2ClientService->client();
        $this->bucket = $this->r2ClientService->bucket();
    }

    /**
     * Check if user's quota allows the new file.
     */
    public function checkQuota(User $user, int $newFileSize): bool
    {
        return $this->quotaService->checkQuota($user, $newFileSize);
    }

    /**
     * Generate a deterministic, collision-safe R2 object key.
     */
    public function generateObjectKey(User $user, string $filename): string
    {
        return sprintf('%d/%s/%s', $user->id, Str::uuid()->toString(), $filename);
    }

    /**
     * Initialize a multipart upload on R2.
     */
    public function initUpload(User $user, string $filename, string $mimeType, int $sizeBytes, ?int $folderId): UploadSession
    {
        $objectKey = $this->generateObjectKey($user, $filename);

        $result = $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $objectKey,
            'ContentType' => $mimeType,
        ]);

        return UploadSession::query()->create([
            'user_id' => $user->id,
            'folder_id' => $folderId,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'r2_object_key' => $objectKey,
            'r2_upload_id' => $result['UploadId'],
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * Generate a presigned URL for uploading a specific part.
     */
    public function presignPart(UploadSession $session, int $partNumber): string
    {
        $command = $this->s3->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $session->r2_object_key,
            'UploadId' => $session->r2_upload_id,
            'PartNumber' => $partNumber,
        ]);

        $presignedRequest = $this->s3->createPresignedRequest($command, config('filesystems.signed_url_upload_lifetime', '+2 hours'));

        return (string) $presignedRequest->getUri();
    }

    /**
     * Complete the multipart upload and create a File record.
     * Also increments user's quota usage.
     *
     * Wrapped in a DB transaction for atomicity.
     *
     * @param  array<int, array{part_number: int, etag: string}>  $parts
     */
    public function completeUpload(UploadSession $session, array $parts): File
    {
        $multipartUpload = array_map(fn (array $part) => [
            'PartNumber' => $part['part_number'],
            'ETag' => $part['etag'],
        ], $parts);

        $this->s3->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $session->r2_object_key,
            'UploadId' => $session->r2_upload_id,
            'MultipartUpload' => [
                'Parts' => $multipartUpload,
            ],
        ]);

        // Compute SHA256 checksum from uploaded object
        $checksum = null;
        try {
            $objectResult = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $session->r2_object_key,
            ]);
            $checksum = hash('sha256', (string) $objectResult['Body']);
        } catch (\Throwable) {
            // Non-critical: checksum will remain null if fetch fails
        }

        return DB::transaction(function () use ($session, $parts, $checksum): File {
            $session->update([
                'status' => 'completed',
                'total_parts' => count($parts),
            ]);

            $fileName = $this->resolveFileName(
                $session->filename,
                $session->folder_id,
                $session->user_id,
            );

            $file = File::query()->create([
                'name' => $fileName,
                'folder_id' => $session->folder_id,
                'owner_id' => $session->user_id,
                'size_bytes' => $session->size_bytes,
                'mime_type' => $session->mime_type,
                'r2_object_key' => $session->r2_object_key,
                'version' => 1,
                'checksum_sha256' => $checksum,
            ]);

            // Increment user's quota usage
            $user = User::findOrFail($session->user_id);
            $this->quotaService->incrementUsage($user, $session->size_bytes);

            // Record sync event inside transaction
            $this->syncEventService->record(
                $session->user_id,
                'create',
                'file',
                (string) $file->id,
                [
                    'name' => $file->name,
                    'folder_id' => $file->folder?->uuid,
                    'size_bytes' => $file->size_bytes,
                    'mime_type' => $file->mime_type,
                    'resource_type' => 'file',
                ],
            );

            // Dispatch EXIF extraction for supported image types
            if (in_array($session->mime_type, ['image/jpeg', 'image/heic'], true)) {
                ExtractExifJob::dispatch((string) $file->id);
            }

            // Dispatch thumbnail + blurhash generation for supported image types
            if (in_array($session->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                GenerateThumbnailAndBlurhashJob::dispatch((string) $file->id);
            }

            return $file;
        });
    }

    /**
     * Abort a multipart upload on R2 and mark session as aborted.
     */
    public function abortUpload(UploadSession $session): void
    {
        $this->s3->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $session->r2_object_key,
            'UploadId' => $session->r2_upload_id,
        ]);

        $session->update(['status' => 'aborted']);
    }

    /**
     * Cleanup expired pending upload sessions.
     *
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpired(): int
    {
        $expired = UploadSession::query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $session) {
            try {
                $this->abortUpload($session);
            } catch (\Throwable) {
                $session->update(['status' => 'expired']);
            }
        }

        return $expired->count();
    }

    /**
     * Resolve a unique file name within the target folder.
     *
     * If a file with the same name already exists (and isn't trashed),
     * appends a numeric suffix: "photo.png" → "photo (1).png" → "photo (2).png".
     */
    private function resolveFileName(string $originalName, ?int $folderId, int $ownerId): string
    {
        $query = File::query()
            ->where('owner_id', $ownerId)
            ->whereNull('deleted_at');

        if ($folderId === null) {
            $query->whereNull('folder_id');
        } else {
            $query->where('folder_id', $folderId);
        }

        if (! $query->clone()->where('name', $originalName)->exists()) {
            return $originalName;
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseName = $extension !== ''
            ? substr($originalName, 0, -(strlen($extension) + 1))
            : $originalName;

        $counter = 1;
        do {
            $candidate = $extension !== ''
                ? "{$baseName} ({$counter}).{$extension}"
                : "{$baseName} ({$counter})";
            $counter++;
        } while ($query->clone()->where('name', $candidate)->exists());

        return $candidate;
    }
}
