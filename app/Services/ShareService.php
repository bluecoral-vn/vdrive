<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;

class ShareService
{
    public function __construct(
        private FolderService $folderService,
        private FavoriteService $favoriteService,
        private TagService $tagService,
    ) {}

    /**
     * Create a file share — either user-to-user or guest link.
     *
     * @return array{share: Share, token: string|null}
     */
    public function createShare(
        User $sharer,
        File $file,
        ?int $recipientId,
        string $permission,
        ?Carbon $expiresAt = null,
        ?string $notes = null,
    ): array {
        // Look for ANY existing share for the same file/owner/recipient
        // (ignoring expires_at so expired links are reused, not duplicated)
        $existing = Share::query()
            ->where('file_id', $file->id)
            ->where('shared_by', $sharer->id)
            ->where(fn ($q) => $recipientId === null
                ? $q->whereNull('shared_with')
                : $q->where('shared_with', $recipientId))
            ->first();

        if ($existing) {
            // Update permission / expiry / notes if changed
            $existing->update([
                'permission' => $permission,
                'expires_at' => $expiresAt,
                'notes' => $notes,
            ]);

            $rawToken = null;
            if ($recipientId === null) {
                // Regenerate token so caller can display it
                $rawToken = Str::random(64);
                $existing->update([
                    'token_hash' => hash('sha256', $rawToken),
                    'token' => $rawToken,
                ]);
            }

            return ['share' => $existing->refresh(), 'token' => $rawToken];
        }

        $rawToken = null;
        $tokenHash = null;

        if ($recipientId === null) {
            $rawToken = Str::random(64);
            $tokenHash = hash('sha256', $rawToken);
        }

        $share = Share::query()->create([
            'file_id' => $file->id,
            'shared_by' => $sharer->id,
            'shared_with' => $recipientId,
            'token_hash' => $tokenHash,
            'token' => $rawToken,
            'permission' => $permission,
            'notes' => $notes,
            'expires_at' => $expiresAt,
        ]);

        return ['share' => $share, 'token' => $rawToken];
    }

    /**
     * Create a folder share — either user-to-user or guest link.
     *
     * @return array{share: Share, token: string|null}
     */
    public function createFolderShare(
        User $sharer,
        Folder $folder,
        ?int $recipientId,
        string $permission,
        ?Carbon $expiresAt = null,
        ?string $notes = null,
    ): array {
        // Look for ANY existing share for the same folder/owner/recipient
        // (ignoring expires_at so expired links are reused, not duplicated)
        $existing = Share::query()
            ->where('folder_id', $folder->id)
            ->where('shared_by', $sharer->id)
            ->where(fn ($q) => $recipientId === null
                ? $q->whereNull('shared_with')
                : $q->where('shared_with', $recipientId))
            ->first();

        if ($existing) {
            $existing->update([
                'permission' => $permission,
                'expires_at' => $expiresAt,
                'notes' => $notes,
            ]);

            $rawToken = null;
            if ($recipientId === null) {
                $rawToken = Str::random(64);
                $existing->update([
                    'token_hash' => hash('sha256', $rawToken),
                    'token' => $rawToken,
                ]);
            }

            return ['share' => $existing->refresh(), 'token' => $rawToken];
        }

        $rawToken = null;
        $tokenHash = null;

        if ($recipientId === null) {
            $rawToken = Str::random(64);
            $tokenHash = hash('sha256', $rawToken);
        }

        $share = Share::query()->create([
            'folder_id' => $folder->id,
            'shared_by' => $sharer->id,
            'shared_with' => $recipientId,
            'token_hash' => $tokenHash,
            'token' => $rawToken,
            'permission' => $permission,
            'notes' => $notes,
            'expires_at' => $expiresAt,
        ]);

        return ['share' => $share, 'token' => $rawToken];
    }

    /**
     * Find a share by raw guest token (hash-then-lookup).
     */
    public function findByToken(string $rawToken): ?Share
    {
        $hash = hash('sha256', $rawToken);

        return Share::query()
            ->where('token_hash', $hash)
            ->with(['file', 'folder'])
            ->first();
    }

    /**
     * List shares targeting the authenticated user (cursor-paginated).
     * Includes both file shares and folder shares.
     *
     * @return CursorPaginator<Share>
     */
    public function sharedWithMe(User $user, int $limit = 15): CursorPaginator
    {
        return Share::query()
            ->where('shared_with', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with(['file', 'folder', 'sharedBy', 'sharedWith'])
            ->orderBy('created_at', 'desc')
            ->cursorPaginate($limit);
    }

    /**
     * List shares created by the authenticated user (cursor-paginated).
     * Includes both file shares and folder shares, with nested resource.
     *
     * @return CursorPaginator<Share>
     */
    public function sharedByMe(User $user, int $limit = 15): CursorPaginator
    {
        return Share::query()
            ->where('shared_by', $user->id)
            ->with(['file', 'folder', 'sharedBy', 'sharedWith'])
            ->orderBy('created_at', 'desc')
            ->cursorPaginate($limit);
    }

    /**
     * Check if a file is shared with a specific user (direct share).
     */
    public function isSharedWith(File $file, User $user): bool
    {
        return Share::query()
            ->where('file_id', $file->id)
            ->where('shared_with', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Get the share permission level for a user on a file (direct share).
     */
    public function getSharePermission(File $file, User $user): ?string
    {
        $share = Share::query()
            ->where('file_id', $file->id)
            ->where('shared_with', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        return $share?->permission;
    }

    /**
     * Check if a folder has a direct share with a specific user.
     */
    public function isFolderSharedWith(Folder $folder, User $user): bool
    {
        return Share::query()
            ->where('folder_id', $folder->id)
            ->where('shared_with', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Get the best inherited share permission for a user on a folder.
     *
     * Walks the folder's ancestor chain (including the folder itself) and
     * checks for any active folder shares with the user. Returns the most
     * permissive level found ('edit' > 'view').
     *
     * No recursive queries — collects ancestor IDs then does ONE share lookup.
     */
    public function getAncestorSharePermission(?int $folderId, User $user): ?string
    {
        $ancestorIds = $this->folderService->getAncestorIds($folderId);

        if ($ancestorIds === []) {
            return null;
        }

        $shares = Share::query()
            ->whereIn('folder_id', $ancestorIds)
            ->where('shared_with', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get(['permission']);

        if ($shares->isEmpty()) {
            return null;
        }

        // Return most permissive: edit > view
        foreach ($shares as $share) {
            if ($share->permission === 'edit') {
                return 'edit';
            }
        }

        return 'view';
    }

    /**
     * Delete (revoke) a share and clean up recipient's user-scoped metadata.
     */
    public function revoke(Share $share): void
    {
        $recipientId = $share->shared_with;

        // Capture resource info before deleting the share
        $isFileShare = $share->isFileShare();
        $fileId = $share->file_id;
        $folderId = $share->folder_id;

        $share->delete();

        // Guest links have no user metadata to clean
        if ($recipientId === null) {
            return;
        }

        if ($isFileShare && $fileId !== null) {
            $this->cleanupUserMetadata($recipientId, 'file', [$fileId], 'folder', []);
        } elseif ($folderId !== null) {
            $this->cleanupFolderShareMetadata($recipientId, $folderId);
        }
    }

    /**
     * Clean up recipient metadata for a revoked folder share.
     * Collects the folder + all descendants, then removes favorites and tags.
     */
    private function cleanupFolderShareMetadata(int $userId, int $folderId): void
    {
        $folder = Folder::query()->find($folderId);

        if (! $folder) {
            return;
        }

        // Collect all descendant folders using materialized path
        $descendantFolders = Folder::query()
            ->where('path', 'like', $folder->path.'%')
            ->get();

        $folderUuids = $descendantFolders->pluck('uuid')->map(fn ($v) => (string) $v)->all();
        $folderIds = $descendantFolders->pluck('id')->all();

        // Collect all files inside the folder tree
        $fileIds = File::query()
            ->whereIn('folder_id', $folderIds)
            ->pluck('id')
            ->map(fn ($v) => (string) $v)
            ->all();

        $this->cleanupUserMetadata($userId, 'file', $fileIds, 'folder', $folderUuids);
    }

    /**
     * Remove a user's favorites and tags for the given resource IDs.
     *
     * @param  list<string>  $fileIds
     * @param  list<string>  $folderUuids
     */
    private function cleanupUserMetadata(int $userId, string $fileType, array $fileIds, string $folderType, array $folderUuids): void
    {
        $this->favoriteService->cleanupForUser($userId, $fileType, $fileIds);
        $this->tagService->cleanupForUser($userId, $fileType, $fileIds);
        $this->favoriteService->cleanupForUser($userId, $folderType, $folderUuids);
        $this->tagService->cleanupForUser($userId, $folderType, $folderUuids);
    }

    /**
     * Remove duplicate guest-link rows from the shares table.
     *
     * For each (file_id/folder_id, shared_by, shared_with IS NULL) group
     * that has more than one row, keeps the newest and deletes the rest.
     *
     * @return int Number of duplicate rows deleted
     */
    public function cleanupDuplicateGuestLinks(): int
    {
        $deleted = 0;

        // --- File guest-link duplicates ---
        $fileGroups = Share::query()
            ->whereNotNull('file_id')
            ->whereNull('shared_with')
            ->selectRaw('file_id, shared_by, COUNT(*) as cnt')
            ->groupBy('file_id', 'shared_by')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($fileGroups as $group) {
            $ids = Share::query()
                ->where('file_id', $group->file_id)
                ->where('shared_by', $group->shared_by)
                ->whereNull('shared_with')
                ->orderByDesc('created_at')
                ->pluck('id');

            // Keep the first (newest), delete the rest
            $toDelete = $ids->slice(1);
            $deleted += Share::query()->whereIn('id', $toDelete)->delete();
        }

        // --- Folder guest-link duplicates ---
        $folderGroups = Share::query()
            ->whereNotNull('folder_id')
            ->whereNull('shared_with')
            ->selectRaw('folder_id, shared_by, COUNT(*) as cnt')
            ->groupBy('folder_id', 'shared_by')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($folderGroups as $group) {
            $ids = Share::query()
                ->where('folder_id', $group->folder_id)
                ->where('shared_by', $group->shared_by)
                ->whereNull('shared_with')
                ->orderByDesc('created_at')
                ->pluck('id');

            $toDelete = $ids->slice(1);
            $deleted += Share::query()->whereIn('id', $toDelete)->delete();
        }

        // --- User-to-user duplicate shares ---
        $userFileGroups = Share::query()
            ->whereNotNull('file_id')
            ->whereNotNull('shared_with')
            ->selectRaw('file_id, shared_by, shared_with, COUNT(*) as cnt')
            ->groupBy('file_id', 'shared_by', 'shared_with')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($userFileGroups as $group) {
            $ids = Share::query()
                ->where('file_id', $group->file_id)
                ->where('shared_by', $group->shared_by)
                ->where('shared_with', $group->shared_with)
                ->orderByDesc('created_at')
                ->pluck('id');

            $toDelete = $ids->slice(1);
            $deleted += Share::query()->whereIn('id', $toDelete)->delete();
        }

        $userFolderGroups = Share::query()
            ->whereNotNull('folder_id')
            ->whereNotNull('shared_with')
            ->selectRaw('folder_id, shared_by, shared_with, COUNT(*) as cnt')
            ->groupBy('folder_id', 'shared_by', 'shared_with')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($userFolderGroups as $group) {
            $ids = Share::query()
                ->where('folder_id', $group->folder_id)
                ->where('shared_by', $group->shared_by)
                ->where('shared_with', $group->shared_with)
                ->orderByDesc('created_at')
                ->pluck('id');

            $toDelete = $ids->slice(1);
            $deleted += Share::query()->whereIn('id', $toDelete)->delete();
        }

        return $deleted;
    }

    /**
     * Check whether a folder is a descendant of (or equal to) the shared root folder.
     * Uses materialized path for O(1) lookup — no recursive queries.
     */
    public function isDescendantFolder(int $sharedFolderId, Folder $target): bool
    {
        if ($target->id === $sharedFolderId) {
            return true;
        }

        // Materialized path format: /{ancestorId}/...//{targetId}/
        $sharedFolder = Folder::query()->find($sharedFolderId);
        if (! $sharedFolder) {
            return false;
        }

        // Target's path must start with shared folder's path
        return str_starts_with($target->path, $sharedFolder->path);
    }

    /**
     * Check whether a file belongs to any folder within the shared folder tree.
     */
    public function isFileInsideSharedFolder(int $sharedFolderId, File $file): bool
    {
        if ($file->folder_id === null) {
            return false;
        }

        if ($file->folder_id === $sharedFolderId) {
            return true;
        }

        $folder = Folder::query()->find($file->folder_id);
        if (! $folder) {
            return false;
        }

        return $this->isDescendantFolder($sharedFolderId, $folder);
    }
}
