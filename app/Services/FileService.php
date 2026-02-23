<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class FileService
{
    public function __construct(
        private R2ClientService $r2ClientService,
        private QuotaService $quotaService,
        private SyncEventService $syncEventService,
    ) {}

    /**
     * Get paginated files in a folder.
     *
     * @return CursorPaginator<File>
     */
    public function filesInFolder(int $folderId, int $limit = 15): CursorPaginator
    {
        return File::query()
            ->notTrashed()
            ->where('folder_id', $folderId)
            ->orderBy('name')
            ->cursorPaginate($limit);
    }

    /**
     * Get paginated files at root level (no folder).
     *
     * @return CursorPaginator<File>
     */
    public function rootFiles(int $ownerId, int $limit = 15): CursorPaginator
    {
        return File::query()
            ->notTrashed()
            ->where('owner_id', $ownerId)
            ->whereNull('folder_id')
            ->orderBy('name')
            ->cursorPaginate($limit);
    }

    /**
     * Rename a file, increment version, and record sync event.
     */
    public function rename(File $file, string $newName): File
    {
        $oldName = $file->name;

        $file->update(['name' => $newName]);
        $file->increment('version');
        $file->refresh();

        $this->syncEventService->record(
            $file->owner_id,
            'rename',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'old_name' => $oldName, 'resource_type' => 'file'],
        );

        return $file;
    }

    /**
     * Delete a file record, its R2 object, and decrement quota.
     */
    public function delete(File $file): void
    {
        $s3 = $this->r2ClientService->client();
        $bucket = $this->r2ClientService->bucket();

        try {
            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $file->r2_object_key,
            ]);
        } catch (\Throwable) {
            report(new \RuntimeException("Failed to delete R2 object: {$file->r2_object_key}"));
        }

        // Decrement owner's quota
        $owner = User::find($file->owner_id);
        if ($owner) {
            $this->quotaService->decrementUsage($owner, $file->size_bytes);
        }

        $file->delete();
    }
}
