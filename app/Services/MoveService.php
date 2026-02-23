<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveService
{
    public function __construct(
        private SyncEventService $syncEventService,
        private PermissionContext $permissionContext,
    ) {}

    /**
     * Assert that a non-owner move stays within the authorized subtree.
     *
     * - Owners are unrestricted (no subtree boundary).
     * - Non-owners must move within the same shared folder subtree.
     * - Moving to root (null target) as non-owner is always blocked.
     */
    private function assertSubtreeBoundary(User $user, int $resourceOwnerId, ?string $sourceFolderPath, ?string $targetFolderPath): void
    {
        // Owners can move freely
        if ($user->id === $resourceOwnerId) {
            return;
        }

        // System admins can move freely
        if ($this->permissionContext->hasPermission('files.update-any') || $this->permissionContext->hasPermission('folders.update-any')) {
            return;
        }

        // Non-owner moving to root is always blocked
        if ($targetFolderPath === null) {
            abort(403);
        }

        // Find the subtree root that grants edit on the source
        $subtreeRoot = $this->permissionContext->getEditSubtreeRoot($sourceFolderPath);

        if ($subtreeRoot === null) {
            // No folder share grants edit — should not happen if we got here,
            // but block to be safe
            abort(403);
        }

        // Target must be within the same subtree
        if (! str_starts_with($targetFolderPath, $subtreeRoot)) {
            abort(403);
        }
    }

    /**
     * Move a single file to a different folder (or root).
     *
     * Rules:
     * - File must not be trashed
     * - Target folder (if set) must exist, not be trashed, and belong to same owner
     * - No-op if already in the target folder
     * - Does NOT modify S3 object or quota
     */
    public function moveFile(File $file, ?int $targetFolderId, User $user): File
    {
        if ($file->isTrashed()) {
            throw ValidationException::withMessages([
                'file' => 'Cannot move a trashed file.',
            ]);
        }

        // No-op: already in the target folder
        if ($file->folder_id === $targetFolderId) {
            return $file;
        }

        if ($targetFolderId !== null) {
            $targetFolder = Folder::query()->find($targetFolderId);

            if (! $targetFolder) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Target folder does not exist.',
                ]);
            }

            if ($targetFolder->isTrashed()) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Cannot move into a trashed folder.',
                ]);
            }

            if (! $this->permissionContext->canEditFolder($targetFolder->id, $targetFolder->owner_id, $targetFolder->path)) {
                abort(403);
            }
        }

        // Name collision check at target (includes trashed — matches DB UNIQUE constraint)
        $nameCollisionQuery = File::query()
            ->where('owner_id', $file->owner_id)
            ->where('name', $file->name)
            ->where('id', '!=', $file->id);

        if ($targetFolderId === null) {
            $nameCollisionQuery->whereNull('folder_id');
        } else {
            $nameCollisionQuery->where('folder_id', $targetFolderId);
        }

        if ($nameCollisionQuery->exists()) {
            throw ValidationException::withMessages([
                'folder_id' => 'A file with the same name already exists in the target folder.',
            ]);
        }

        // Cross-boundary check: non-owner must stay within authorized subtree
        $this->assertSubtreeBoundary(
            $user,
            $file->owner_id,
            $file->folder?->path,
            $targetFolderId !== null ? $targetFolder->path : null,
        );

        $oldFolderId = $file->folder_id;

        $file->update(['folder_id' => $targetFolderId]);
        $file->increment('version');
        $file->refresh();

        $this->syncEventService->record(
            $file->owner_id,
            'move',
            'file',
            (string) $file->id,
            [
                'name' => $file->name,
                'old_parent_id' => $oldFolderId ? Folder::query()->where('id', $oldFolderId)->value('uuid') : null,
                'new_parent_id' => $file->folder?->uuid,
                'resource_type' => 'file',
            ],
        );

        return $file;
    }

    /**
     * Move a single folder to a different parent (or root).
     *
     * Rules:
     * - Folder must not be trashed
     * - Target parent (if set) must exist, not be trashed, and belong to same owner
     * - Cannot move into self or a descendant (circular reference)
     * - Batch-updates materialized path for all descendants
     */
    public function moveFolder(Folder $folder, ?int $targetParentId, User $user): Folder
    {
        if ($folder->isTrashed()) {
            throw ValidationException::withMessages([
                'folder' => 'Cannot move a trashed folder.',
            ]);
        }

        // No-op: already in the target parent
        if ($folder->parent_id === $targetParentId) {
            return $folder;
        }

        // Cannot move into self
        if ($targetParentId === $folder->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'Cannot move a folder into itself.',
            ]);
        }

        $targetParent = null;

        if ($targetParentId !== null) {
            $targetParent = Folder::query()->find($targetParentId);

            if (! $targetParent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Target parent folder does not exist.',
                ]);
            }

            if ($targetParent->isTrashed()) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Cannot move into a trashed folder.',
                ]);
            }

            if (! $this->permissionContext->canEditFolder($targetParent->id, $targetParent->owner_id, $targetParent->path)) {
                abort(403);
            }

            // Circular reference check via materialized path (O(1))
            if ($targetParent->path !== null && str_starts_with($targetParent->path, $folder->path)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Cannot move a folder into one of its descendants.',
                ]);
            }
        }

        // Name collision check at target (includes trashed — matches DB UNIQUE constraint)
        $nameCollision = Folder::query()
            ->where('owner_id', $folder->owner_id)
            ->where('parent_id', $targetParentId)
            ->where('name', $folder->name)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($nameCollision) {
            throw ValidationException::withMessages([
                'parent_id' => 'A folder with the same name already exists in the target location.',
            ]);
        }

        // Cross-boundary check: non-owner must stay within authorized subtree
        $this->assertSubtreeBoundary(
            $user,
            $folder->owner_id,
            $folder->path,
            $targetParent?->path,
        );

        $oldPath = $folder->path;
        $oldParentId = $folder->parent_id;

        // Compute new path
        if ($targetParentId === null) {
            $newPath = '/'.$folder->id.'/';
        } else {
            $newPath = $targetParent->path.$folder->id.'/';
        }

        DB::transaction(function () use ($folder, $targetParentId, $oldPath, $newPath, $oldParentId) {
            // Update the folder itself
            $folder->update([
                'parent_id' => $targetParentId,
                'path' => $newPath,
            ]);

            // Batch update all descendant folder paths
            if ($oldPath !== $newPath) {
                Folder::query()
                    ->where('path', 'LIKE', $oldPath.'%')
                    ->where('id', '!=', $folder->id)
                    ->update([
                        'path' => DB::raw(
                            'REPLACE(path, '
                            .DB::connection()->getPdo()->quote($oldPath)
                            .', '
                            .DB::connection()->getPdo()->quote($newPath)
                            .')'
                        ),
                    ]);
            }

            // Record sync event inside the transaction
            $this->syncEventService->record(
                $folder->owner_id,
                'move',
                'folder',
                (string) $folder->uuid,
                [
                    'name' => $folder->name,
                    'old_parent_id' => $oldParentId ? Folder::query()->where('id', $oldParentId)->value('uuid') : null,
                    'new_parent_id' => $targetParentId ? Folder::query()->where('id', $targetParentId)->value('uuid') : null,
                    'resource_type' => 'folder',
                ],
            );
        });

        return $folder->refresh();
    }

    /**
     * Bulk move files and folders to a target folder (or root).
     *
     * - Validates ALL items upfront before any mutation (fail-fast)
     * - Entire mutation wrapped in DB transaction for atomicity
     */
    public function bulkMove(array $fileIds, array $folderIds, ?int $targetFolderId, User $user): array
    {
        // ── Phase 1: Validate everything upfront ────────────

        // Validate target folder
        $targetFolder = null;

        if ($targetFolderId !== null) {
            $targetFolder = Folder::query()->find($targetFolderId);

            if (! $targetFolder) {
                throw ValidationException::withMessages([
                    'target_folder_id' => 'Target folder does not exist.',
                ]);
            }

            if ($targetFolder->isTrashed()) {
                throw ValidationException::withMessages([
                    'target_folder_id' => 'Cannot move into a trashed folder.',
                ]);
            }

            if (! $this->permissionContext->canEditFolder($targetFolder->id, $targetFolder->owner_id, $targetFolder->path)) {
                abort(403);
            }
        }

        // Resolve and validate all files
        $files = [];
        foreach ($fileIds as $fileId) {
            $file = File::query()->find($fileId);

            if (! $file) {
                throw ValidationException::withMessages([
                    'files' => "File {$fileId} does not exist.",
                ]);
            }

            if ($file->owner_id !== $user->id && ! $this->permissionContext->canEditFile((string) $file->id, $file->owner_id, $file->folder?->path)) {
                abort(403);
            }

            $this->assertSubtreeBoundary(
                $user,
                $file->owner_id,
                $file->folder?->path,
                $targetFolderId !== null ? $targetFolder->path : null,
            );

            $files[] = $file;
        }

        // Resolve and validate all folders
        $folders = [];
        foreach ($folderIds as $folderId) {
            $folder = Folder::query()->find($folderId);

            if (! $folder) {
                throw ValidationException::withMessages([
                    'folders' => "Folder {$folderId} does not exist.",
                ]);
            }

            if ($folder->owner_id !== $user->id && ! $this->permissionContext->canEditFolder($folder->id, $folder->owner_id, $folder->path)) {
                abort(403);
            }

            $this->assertSubtreeBoundary(
                $user,
                $folder->owner_id,
                $folder->path,
                $targetFolderId !== null ? $targetFolder->path : null,
            );

            $folders[] = $folder;
        }

        // ── Phase 2: Execute all moves atomically ───────────

        $movedFiles = 0;
        $movedFolders = 0;

        DB::transaction(function () use ($files, $folders, $targetFolderId, $user, &$movedFiles, &$movedFolders) {
            foreach ($files as $file) {
                $this->moveFile($file, $targetFolderId, $user);
                $movedFiles++;
            }

            foreach ($folders as $folder) {
                $this->moveFolder($folder, $targetFolderId, $user);
                $movedFolders++;
            }
        });

        return [
            'moved_files' => $movedFiles,
            'moved_folders' => $movedFolders,
        ];
    }
}
