<?php

namespace App\Services;

/**
 * Immutable value object holding preloaded permission data for a single request.
 *
 * Built once by PermissionContextBuilder, then reused across the entire
 * request lifecycle. All permission checks become in-memory lookups.
 */
class PermissionContext
{
    /**
     * @param  int  $userId  Authenticated user's ID
     * @param  array<string>  $permissions  All permission slugs the user holds via roles
     * @param  array<string, string>  $directFileShares  file_id → permission ('view'|'edit')
     * @param  array<array{folder_id: int, path: string, permission: string}>  $folderShares  Active folder shares with paths
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $permissions,
        public readonly array $directFileShares,
        public readonly array $folderShares,
    ) {}

    /**
     * Check if the user has a specific permission slug.
     */
    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissions, true);
    }

    /**
     * Check if the user can view a file (in-memory, no DB).
     *
     * Resolution order:
     * 1. System permission (files.view-any)
     * 2. Ownership
     * 3. Direct file share
     * 4. Inherited folder share (path prefix match)
     */
    public function canViewFile(string $fileId, int $ownerId, ?string $folderPath): bool
    {
        if ($this->hasPermission('files.view-any')) {
            return true;
        }

        if ($this->userId === $ownerId) {
            return true;
        }

        if (isset($this->directFileShares[$fileId])) {
            return true;
        }

        return $this->hasInheritedFolderAccess($folderPath) !== null;
    }

    /**
     * Check if the user can download a file (in-memory, no DB).
     *
     * Since 'view' now includes download, this is equivalent to canViewFile.
     */
    public function canDownloadFile(string $fileId, int $ownerId, ?string $folderPath): bool
    {
        if ($this->hasPermission('files.download-any')) {
            return true;
        }

        if ($this->userId === $ownerId) {
            return true;
        }

        // Any share permission (view or edit) grants download
        if (isset($this->directFileShares[$fileId])) {
            return true;
        }

        return $this->hasInheritedFolderAccess($folderPath) !== null;
    }

    /**
     * Check if the user can view a folder (in-memory, no DB).
     *
     * Resolution order:
     * 1. System permission (folders.view-any)
     * 2. Ownership
     * 3. Direct folder share (exact folder_id match)
     * 4. Inherited folder share (folder path starts with shared folder path)
     */
    public function canViewFolder(int $folderId, int $ownerId, ?string $folderPath): bool
    {
        if ($this->hasPermission('folders.view-any')) {
            return true;
        }

        if ($this->userId === $ownerId) {
            return true;
        }

        // Direct folder share
        foreach ($this->folderShares as $share) {
            if ($share['folder_id'] === $folderId) {
                return true;
            }
        }

        // Inherited folder share (path prefix)
        if ($folderPath !== null) {
            foreach ($this->folderShares as $share) {
                if (str_starts_with($folderPath, $share['path']) && $folderPath !== $share['path']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the user can edit a file (in-memory, no DB).
     *
     * Resolution order:
     * 1. System permission (files.update-any)
     * 2. Ownership
     * 3. Direct file share with 'edit'
     * 4. Inherited folder share with 'edit' (path prefix match)
     */
    public function canEditFile(string $fileId, int $ownerId, ?string $folderPath): bool
    {
        if ($this->hasPermission('files.update-any')) {
            return true;
        }

        if ($this->userId === $ownerId) {
            return true;
        }

        if (isset($this->directFileShares[$fileId]) && $this->directFileShares[$fileId] === 'edit') {
            return true;
        }

        return $this->hasInheritedFolderAccess($folderPath) === 'edit';
    }

    /**
     * Check if the user can edit a folder (in-memory, no DB).
     *
     * Resolution order:
     * 1. System permission (folders.update-any)
     * 2. Ownership
     * 3. Direct folder share with 'edit'
     * 4. Inherited folder share with 'edit' (path prefix match)
     */
    public function canEditFolder(int $folderId, int $ownerId, ?string $folderPath): bool
    {
        if ($this->hasPermission('folders.update-any')) {
            return true;
        }

        if ($this->userId === $ownerId) {
            return true;
        }

        // Direct folder share with 'edit'
        foreach ($this->folderShares as $share) {
            if ($share['folder_id'] === $folderId && $share['permission'] === 'edit') {
                return true;
            }
        }

        // Inherited folder share with 'edit' (path prefix)
        if ($folderPath !== null) {
            foreach ($this->folderShares as $share) {
                if (str_starts_with($folderPath, $share['path']) && $folderPath !== $share['path'] && $share['permission'] === 'edit') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the subtree root path that grants edit access to a resource at the given path.
     *
     * Returns the folder share's path that authorizes edit on this path,
     * or null if no folder share grants edit (access might be via ownership
     * or system permission, which have no subtree boundary).
     */
    public function getEditSubtreeRoot(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        foreach ($this->folderShares as $share) {
            if ($share['permission'] === 'edit' && str_starts_with($path, $share['path'])) {
                return $share['path'];
            }
        }

        return null;
    }

    /**
     * Check inherited folder share via path prefix match.
     * Returns the most permissive permission found, or null.
     */
    public function hasInheritedFolderAccess(?string $folderPath): ?string
    {
        if ($folderPath === null || $folderPath === '') {
            return null;
        }

        $bestPermission = null;

        foreach ($this->folderShares as $share) {
            if (str_starts_with($folderPath, $share['path'])) {
                if ($share['permission'] === 'edit') {
                    return 'edit'; // Most permissive, return early
                }
                $bestPermission = 'view';
            }
        }

        return $bestPermission;
    }
}
