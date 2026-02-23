<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds a PermissionContext by executing exactly 3 queries:
 *
 * 1. All permission slugs (user → roles → permissions)
 * 2. All active direct file shares for the user
 * 3. All active folder shares for the user (with folder path)
 *
 * The result is cached per request via singleton binding.
 */
class PermissionContextBuilder
{
    /**
     * Build the permission context for the given user.
     */
    public function build(User $user): PermissionContext
    {
        return new PermissionContext(
            userId: $user->id,
            permissions: $this->loadPermissions($user),
            directFileShares: $this->loadDirectFileShares($user),
            folderShares: $this->loadFolderShares($user),
        );
    }

    /**
     * Query 1: All permission slugs the user has via their roles.
     *
     * @return array<string>
     */
    private function loadPermissions(User $user): array
    {
        return DB::table('role_user')
            ->join('permission_role', 'role_user.role_id', '=', 'permission_role.role_id')
            ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
            ->where('role_user.user_id', $user->id)
            ->distinct()
            ->pluck('permissions.slug')
            ->all();
    }

    /**
     * Query 2: All active direct file shares targeting this user.
     *
     * @return array<string, string> file_id → permission
     */
    private function loadDirectFileShares(User $user): array
    {
        return Share::query()
            ->whereNotNull('file_id')
            ->where('shared_with', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('permission', 'file_id')
            ->all();
    }

    /**
     * Query 3: All active folder shares targeting this user, with folder paths.
     *
     * @return array<array{folder_id: int, path: string, permission: string}>
     */
    private function loadFolderShares(User $user): array
    {
        return Share::query()
            ->whereNotNull('folder_id')
            ->where('shared_with', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->join('folders', 'shares.folder_id', '=', 'folders.id')
            ->select('shares.folder_id', 'folders.path', 'shares.permission')
            ->get()
            ->map(fn ($row) => [
                'folder_id' => $row->folder_id,
                'path' => $row->path,
                'permission' => $row->permission,
            ])
            ->all();
    }
}
