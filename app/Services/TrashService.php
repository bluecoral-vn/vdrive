<?php

namespace App\Services;

use App\Jobs\DeleteR2ObjectJob;
use App\Jobs\EmptyTrashJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\Taggable;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TrashService
{
    public function __construct(
        private QuotaService $quotaService,
        private SystemConfigService $configService,
        private SyncEventService $syncEventService,
    ) {}

    // ── Soft Delete ──────────────────────────────────────

    /**
     * Soft-delete a file.
     */
    public function softDeleteFile(File $file, User $deletedBy): void
    {
        $now = now();
        $purgeAt = $this->calculatePurgeAt($now);

        $file->update([
            'deleted_at' => $now,
            'deleted_by' => $deletedBy->id,
            'purge_at' => $purgeAt,
        ]);
        $file->increment('version');
        $file->refresh();

        $this->syncEventService->record(
            $file->owner_id,
            'delete',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'folder_id' => $file->folder?->uuid, 'resource_type' => 'file'],
        );
    }

    /**
     * Soft-delete a folder and cascade to all descendants.
     * Wrapped in a transaction for atomicity.
     */
    public function softDeleteFolder(Folder $folder, User $deletedBy): void
    {
        $now = now();
        $purgeAt = $this->calculatePurgeAt($now);

        // Collect all descendant folder IDs via BFS (no recursive N+1)
        $allFolderIds = $this->collectDescendantFolderIds($folder->id);
        $allFolderIds[] = $folder->id;

        DB::transaction(function () use ($allFolderIds, $now, $purgeAt, $deletedBy, $folder) {
            // Batch update all folders
            Folder::query()
                ->whereIn('id', $allFolderIds)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $now,
                    'deleted_by' => $deletedBy->id,
                    'purge_at' => $purgeAt,
                    'updated_at' => $now,
                ]);

            // Batch update all files in those folders
            File::query()
                ->whereIn('folder_id', $allFolderIds)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $now,
                    'deleted_by' => $deletedBy->id,
                    'purge_at' => $purgeAt,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            // Record per-folder sync events
            $folderItems = [];
            $folders = Folder::query()->whereIn('id', $allFolderIds)->get(['id', 'uuid', 'name', 'parent_id', 'owner_id']);
            foreach ($folders as $f) {
                $folderItems[] = [
                    'resource_id' => (string) $f->uuid,
                    'metadata' => ['name' => $f->name, 'parent_id' => $f->parent?->uuid, 'resource_type' => 'folder'],
                ];
            }
            if ($folderItems !== []) {
                $this->syncEventService->recordBatch(
                    $folder->owner_id,
                    'delete',
                    'folder',
                    $folderItems,
                );
            }

            // Record per-file sync events
            $fileItems = [];
            $files = File::query()->whereIn('folder_id', $allFolderIds)->whereNotNull('deleted_at')->with('folder')->get(['id', 'name', 'folder_id', 'owner_id']);
            foreach ($files as $f) {
                $fileItems[] = [
                    'resource_id' => (string) $f->id,
                    'metadata' => ['name' => $f->name, 'folder_id' => $f->folder?->uuid, 'resource_type' => 'file'],
                ];
            }
            if ($fileItems !== []) {
                $this->syncEventService->recordBatch(
                    $folder->owner_id,
                    'delete',
                    'file',
                    $fileItems,
                );
            }
        });
    }

    /**
     * Bulk soft-delete files and folders (move to trash).
     *
     * - Validates ALL items upfront before any mutation (fail-fast)
     * - Entire mutation wrapped in DB transaction for atomicity
     *
     * @param  list<string>  $fileIds
     * @param  list<int>  $folderIds  (numeric IDs, already resolved from UUIDs)
     * @return array{deleted_files: int, deleted_folders: int}
     */
    public function bulkDelete(array $fileIds, array $folderIds, User $user): array
    {
        // ── Phase 1: Validate everything upfront ────────────

        $files = [];
        foreach ($fileIds as $fileId) {
            $file = File::query()->find($fileId);

            if (! $file) {
                abort(404);
            }

            if ($file->isTrashed()) {
                continue; // Already trashed — skip
            }

            // Authorization via Gate (uses FilePolicy::delete)
            if (! Gate::forUser($user)->allows('delete', $file)) {
                abort(403);
            }

            $files[] = $file;
        }

        $folders = [];
        foreach ($folderIds as $folderId) {
            $folder = Folder::query()->find($folderId);

            if (! $folder) {
                abort(404);
            }

            if ($folder->isTrashed()) {
                continue; // Already trashed — skip
            }

            // Authorization via Gate (uses FolderPolicy::delete)
            if (! Gate::forUser($user)->allows('delete', $folder)) {
                abort(403);
            }

            $folders[] = $folder;
        }

        // ── Phase 2: Execute all deletes atomically ─────────

        $deletedFiles = 0;
        $deletedFolders = 0;

        DB::transaction(function () use ($files, $folders, $user, &$deletedFiles, &$deletedFolders) {
            foreach ($files as $file) {
                $this->softDeleteFile($file, $user);
                $deletedFiles++;
            }

            foreach ($folders as $folder) {
                $this->softDeleteFolder($folder, $user);
                $deletedFolders++;
            }
        });

        return [
            'deleted_files' => $deletedFiles,
            'deleted_folders' => $deletedFolders,
        ];
    }

    // ── Restore ──────────────────────────────────────────

    /**
     * Restore a trashed file. Parent folder must not be trashed.
     */
    public function restoreFile(File $file): void
    {
        // Validate parent folder is not trashed
        if ($file->folder_id !== null) {
            $parent = Folder::query()->find($file->folder_id);
            if ($parent && $parent->isTrashed()) {
                abort(422, 'Parent folder is in trash. Restore the parent first.');
            }
        }

        $file->update([
            'deleted_at' => null,
            'deleted_by' => null,
            'purge_at' => null,
        ]);
        $file->increment('version');
        $file->refresh();

        $this->syncEventService->record(
            $file->owner_id,
            'restore',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'folder_id' => $file->folder?->uuid, 'resource_type' => 'file'],
        );
    }

    /**
     * Restore a trashed folder and all its descendants.
     * Parent folder must not be trashed.
     * Wrapped in a transaction for atomicity.
     */
    public function restoreFolder(Folder $folder): void
    {
        // Validate parent folder is not trashed
        if ($folder->parent_id !== null) {
            $parent = Folder::query()->find($folder->parent_id);
            if ($parent && $parent->isTrashed()) {
                abort(422, 'Parent folder is in trash. Restore the parent first.');
            }
        }

        // Collect all descendant folder IDs
        $allFolderIds = $this->collectDescendantFolderIds($folder->id);
        $allFolderIds[] = $folder->id;

        $now = now();

        DB::transaction(function () use ($allFolderIds, $now, $folder) {
            // Batch restore all folders
            Folder::query()
                ->whereIn('id', $allFolderIds)
                ->whereNotNull('deleted_at')
                ->update([
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'purge_at' => null,
                    'updated_at' => $now,
                ]);

            // Batch restore all files in those folders
            File::query()
                ->whereIn('folder_id', $allFolderIds)
                ->whereNotNull('deleted_at')
                ->update([
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'purge_at' => null,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            // Record per-folder sync events
            $folderItems = [];
            $folders = Folder::query()->whereIn('id', $allFolderIds)->get(['id', 'uuid', 'name', 'parent_id', 'owner_id']);
            foreach ($folders as $f) {
                $folderItems[] = [
                    'resource_id' => (string) $f->uuid,
                    'metadata' => ['name' => $f->name, 'parent_id' => $f->parent?->uuid, 'resource_type' => 'folder'],
                ];
            }
            if ($folderItems !== []) {
                $this->syncEventService->recordBatch(
                    $folder->owner_id,
                    'restore',
                    'folder',
                    $folderItems,
                );
            }

            // Record per-file sync events
            $fileItems = [];
            $files = File::query()->whereIn('folder_id', $allFolderIds)->whereNull('deleted_at')->with('folder')->get(['id', 'name', 'folder_id', 'owner_id']);
            foreach ($files as $f) {
                $fileItems[] = [
                    'resource_id' => (string) $f->id,
                    'metadata' => ['name' => $f->name, 'folder_id' => $f->folder?->uuid, 'resource_type' => 'file'],
                ];
            }
            if ($fileItems !== []) {
                $this->syncEventService->recordBatch(
                    $folder->owner_id,
                    'restore',
                    'file',
                    $fileItems,
                );
            }
        });
    }

    // ── Force Delete ─────────────────────────────────────

    /**
     * Permanently delete a file (dispatch R2 deletion + quota + DB).
     */
    public function forceDeleteFile(File $file): void
    {
        // Record sync event before deletion (we need the file data)
        $this->syncEventService->record(
            $file->owner_id,
            'purge',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'folder_id' => $file->folder?->uuid, 'resource_type' => 'file'],
        );

        // Dispatch R2 deletion as background job (idempotent)
        DeleteR2ObjectJob::dispatch($file->r2_object_key);

        // Also delete thumbnail from R2 if exists
        if ($file->thumbnail_path !== null && $file->thumbnail_path !== '') {
            DeleteR2ObjectJob::dispatch($file->thumbnail_path);
        }

        // Decrement quota synchronously (must reflect immediately)
        $owner = User::query()->find($file->owner_id);
        if ($owner) {
            $this->quotaService->decrementUsage($owner, $file->size_bytes);
        }

        // Cleanup favorites and taggables for this file
        UserFavorite::query()->where('resource_type', 'file')->where('resource_id', $file->id)->delete();
        Taggable::query()->where('resource_type', 'file')->where('resource_id', $file->id)->delete();

        // Hard delete from DB
        $file->delete();
    }

    /**
     * Permanently delete a folder and all descendants.
     */
    public function forceDeleteFolder(Folder $folder): void
    {
        $allFolderIds = $this->collectDescendantFolderIds($folder->id);
        $allFolderIds[] = $folder->id;

        // Record per-folder sync events before deletion
        $folderItems = [];
        $folders = Folder::query()->whereIn('id', $allFolderIds)->get(['id', 'uuid', 'name', 'parent_id', 'owner_id']);
        foreach ($folders as $f) {
            $folderItems[] = [
                'resource_id' => (string) $f->uuid,
                'metadata' => ['name' => $f->name, 'parent_id' => $f->parent?->uuid, 'resource_type' => 'folder'],
            ];
        }
        if ($folderItems !== []) {
            $this->syncEventService->recordBatch(
                $folder->owner_id,
                'purge',
                'folder',
                $folderItems,
            );
        }

        // Delete all files in those folders (batch R2 dispatch + quota + sync events)
        File::query()
            ->whereIn('folder_id', $allFolderIds)
            ->chunkById(100, function (Collection $files) use ($folder) {
                /** @var Collection<int, File> $files */
                $fileItems = [];
                foreach ($files as $file) {
                    // Dispatch R2 deletion as background job
                    DeleteR2ObjectJob::dispatch($file->r2_object_key);

                    $owner = User::query()->find($file->owner_id);
                    if ($owner) {
                        $this->quotaService->decrementUsage($owner, $file->size_bytes);
                    }

                    $fileItems[] = [
                        'resource_id' => (string) $file->id,
                        'metadata' => ['name' => $file->name, 'folder_id' => $file->folder?->uuid, 'resource_type' => 'file'],
                    ];
                }

                if ($fileItems !== []) {
                    $this->syncEventService->recordBatch(
                        $folder->owner_id,
                        'purge',
                        'file',
                        $fileItems,
                    );
                }

                // Cleanup favorites and taggables for these files
                $fileIdStrings = $files->pluck('id')->map(fn ($id) => (string) $id)->all();
                UserFavorite::query()->where('resource_type', 'file')->whereIn('resource_id', $fileIdStrings)->delete();
                Taggable::query()->where('resource_type', 'file')->whereIn('resource_id', $fileIdStrings)->delete();

                // Batch hard delete
                File::query()
                    ->whereIn('id', $files->pluck('id'))
                    ->delete();
            });

        // Cleanup favorites and taggables for folders (using UUIDs)
        $folderUuids = Folder::query()->whereIn('id', $allFolderIds)->pluck('uuid')->map(fn ($uuid) => (string) $uuid)->all();
        UserFavorite::query()->where('resource_type', 'folder')->whereIn('resource_id', $folderUuids)->delete();
        Taggable::query()->where('resource_type', 'folder')->whereIn('resource_id', $folderUuids)->delete();

        // Hard delete all folders (children first via reverse order)
        Folder::query()
            ->whereIn('id', $allFolderIds)
            ->delete();
    }

    // ── Purge ────────────────────────────────────────────

    /**
     * Purge all expired trash items. Returns count of purged items.
     */
    public function purgeExpired(): int
    {
        $purged = 0;

        // Files first
        File::query()
            ->onlyTrashed()
            ->where('purge_at', '<=', now())
            ->chunkById(100, function (Collection $files) use (&$purged) {
                /** @var Collection<int, File> $files */
                foreach ($files as $file) {
                    // Dispatch R2 deletion as background job
                    DeleteR2ObjectJob::dispatch($file->r2_object_key);

                    $owner = User::query()->find($file->owner_id);
                    if ($owner) {
                        $this->quotaService->decrementUsage($owner, $file->size_bytes);
                    }
                }

                $count = File::query()
                    ->whereIn('id', $files->pluck('id'))
                    ->delete();

                $purged += $count;
            });

        // Then folders
        Folder::query()
            ->onlyTrashed()
            ->where('purge_at', '<=', now())
            ->chunkById(100, function (Collection $folders) use (&$purged) {
                $count = Folder::query()
                    ->whereIn('id', $folders->pluck('id'))
                    ->delete();

                $purged += $count;
            });

        return $purged;
    }

    // ── Empty Trash ──────────────────────────────────────

    /**
     * Dispatch a job to empty all trashed items for a user.
     */
    public function emptyTrash(User $user): void
    {
        EmptyTrashJob::dispatch($user->id);
    }

    // ── Listing ──────────────────────────────────────────

    /**
     * List trashed items for a user (files + folders combined, cursor-paginated).
     *
     * @return CursorPaginator<File>
     */
    public function trashedFiles(User $user, int $limit = 15): CursorPaginator
    {
        return File::query()
            ->onlyTrashed()
            ->where('owner_id', $user->id)
            ->orderBy('deleted_at', 'desc')
            ->cursorPaginate($limit);
    }

    /**
     * @return CursorPaginator<Folder>
     */
    public function trashedFolders(User $user, int $limit = 15): CursorPaginator
    {
        return Folder::query()
            ->onlyTrashed()
            ->where('owner_id', $user->id)
            ->orderBy('deleted_at', 'desc')
            ->cursorPaginate($limit);
    }

    // ── Helpers ──────────────────────────────────────────

    /**
     * Collect all descendant folder IDs using BFS (no recursive queries).
     *
     * @return list<int>
     */
    private function collectDescendantFolderIds(int $parentId): array
    {
        $allIds = [];
        $currentLevelIds = [$parentId];

        while (! empty($currentLevelIds)) {
            $childIds = Folder::query()
                ->whereIn('parent_id', $currentLevelIds)
                ->pluck('id')
                ->all();

            if (empty($childIds)) {
                break;
            }

            $allIds = array_merge($allIds, $childIds);
            $currentLevelIds = $childIds;
        }

        return $allIds;
    }

    /**
     * Calculate purge_at based on retention policy.
     */
    private function calculatePurgeAt(Carbon $deletedAt): Carbon
    {
        $retentionDays = (int) ($this->configService->get('trash_retention_days') ?? 7);
        $retentionDays = max(1, min(90, $retentionDays));

        return $deletedAt->copy()->addDays($retentionDays);
    }
}
