<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;
use App\Services\PermissionContext;

class FolderPolicy
{
    public function __construct(private PermissionContext $context) {}

    /**
     * Permission resolution order (SKILL 4):
     * 1. System permission
     * 2. Role permission
     * 3. Resource ownership
     * 4. Direct folder share
     * 5. Inherited folder share (ancestor path match)
     * 6. Guest token (separate endpoint)
     *
     * All checks are in-memory — no DB queries.
     */
    public function view(User $user, Folder $folder): bool
    {
        return $this->context->canViewFolder(
            $folder->id,
            $folder->owner_id,
            $folder->path,
        );
    }

    /**
     * Can the user create folders?
     *
     * Every authenticated user can create folders in their own space.
     */
    public function create(User $user, ?Folder $parent = null): bool
    {
        // Root folder creation: any authenticated user can create
        if ($parent === null) {
            return true;
        }

        // Subfolder creation: must have edit access to parent
        return $this->context->canEditFolder(
            $parent->id,
            $parent->owner_id,
            $parent->path,
        );
    }

    /**
     * Can the user update (rename or move) this folder?
     */
    public function update(User $user, Folder $folder): bool
    {
        return $this->context->canEditFolder(
            $folder->id,
            $folder->owner_id,
            $folder->path,
        );
    }

    /**
     * Can the user delete this folder?
     */
    public function delete(User $user, Folder $folder): bool
    {
        if ($this->context->hasPermission('folders.delete-any')) {
            return true;
        }

        return $this->context->canEditFolder(
            $folder->id,
            $folder->owner_id,
            $folder->path,
        );
    }

    /**
     * Can the user restore this trashed folder?
     */
    public function restore(User $user, Folder $folder): bool
    {
        if ($this->context->hasPermission('folders.restore-any')) {
            return true;
        }

        return $user->id === $folder->owner_id;
    }

    /**
     * Can the user permanently delete this folder?
     */
    public function forceDelete(User $user, Folder $folder): bool
    {
        if ($this->context->hasPermission('folders.force-delete-any')) {
            return true;
        }

        return $user->id === $folder->owner_id;
    }
}
