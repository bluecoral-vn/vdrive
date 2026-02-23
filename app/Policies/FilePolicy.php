<?php

namespace App\Policies;

use App\Models\File;
use App\Models\User;
use App\Services\PermissionContext;

class FilePolicy
{
    public function __construct(private PermissionContext $context) {}

    /**
     * Permission resolution order (SKILL 4):
     * 1. System permission (files.view-any)
     * 2. Role permission (via hasPermission — covered by #1)
     * 3. Resource ownership
     * 4. Direct share permission
     * 5. Inherited folder share (via path match)
     * 6. Guest token (handled separately via token endpoint)
     *
     * All checks are in-memory — no DB queries.
     */
    public function view(User $user, File $file): bool
    {
        return $this->context->canViewFile(
            $file->id,
            $file->owner_id,
            $file->folder?->path,
        );
    }

    /**
     * Can the user update (rename) this file?
     */
    public function update(User $user, File $file): bool
    {
        return $this->context->canEditFile(
            $file->id,
            $file->owner_id,
            $file->folder?->path,
        );
    }

    /**
     * Can the user delete this file?
     */
    public function delete(User $user, File $file): bool
    {
        if ($this->context->hasPermission('files.delete-any')) {
            return true;
        }

        return $this->context->canEditFile(
            $file->id,
            $file->owner_id,
            $file->folder?->path,
        );
    }

    /**
     * Can the user download this file?
     * View permission now includes download.
     */
    public function download(User $user, File $file): bool
    {
        return $this->context->canViewFile(
            $file->id,
            $file->owner_id,
            $file->folder?->path,
        );
    }

    /**
     * Can the user preview this file?
     * Same as view — preview is a read-only operation.
     */
    public function preview(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    /**
     * Can the user view text content of this file?
     * Same as view — content is a read-only operation.
     */
    public function content(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    /**
     * Can the user view the thumbnail of this file?
     * Same as view — thumbnail is a read-only operation.
     */
    public function thumbnail(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    /**
     * Can the user restore this trashed file?
     */
    public function restore(User $user, File $file): bool
    {
        if ($this->context->hasPermission('files.restore-any')) {
            return true;
        }

        return $user->id === $file->owner_id;
    }

    /**
     * Can the user permanently delete this file?
     */
    public function forceDelete(User $user, File $file): bool
    {
        if ($this->context->hasPermission('files.force-delete-any')) {
            return true;
        }

        return $user->id === $file->owner_id;
    }
}
