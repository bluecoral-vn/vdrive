<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

class FavoriteService
{
    /**
     * Add a single resource to user's favorites.
     */
    public function add(User $user, string $resourceType, string $resourceId): UserFavorite
    {
        $this->validateResourceAccess($user, $resourceType, $resourceId);

        return UserFavorite::query()->firstOrCreate([
            'user_id' => $user->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    /**
     * Remove a single resource from user's favorites.
     */
    public function remove(User $user, string $resourceType, string $resourceId): void
    {
        $deleted = UserFavorite::query()
            ->where('user_id', $user->id)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->delete();

        if ($deleted === 0) {
            abort(404);
        }
    }

    /**
     * Bulk add resources to favorites.
     * Validates ALL resources upfront before mutating — all-or-nothing.
     *
     * @param  list<string>  $resourceIds
     */
    public function bulkAdd(User $user, string $resourceType, array $resourceIds): int
    {
        // Validate all resources before any mutation (fail-fast)
        $this->validateBatchResourceAccess($user, $resourceType, $resourceIds);

        $added = 0;

        foreach ($resourceIds as $resourceId) {
            $favorite = UserFavorite::query()->firstOrCreate([
                'user_id' => $user->id,
                'resource_type' => $resourceType,
                'resource_id' => (string) $resourceId,
            ]);

            if ($favorite->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Bulk remove resources from favorites.
     *
     * @param  list<string>  $resourceIds
     */
    public function bulkRemove(User $user, string $resourceType, array $resourceIds): int
    {
        return UserFavorite::query()
            ->where('user_id', $user->id)
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * List user's favorites (cursor-paginated).
     *
     * @return CursorPaginator<UserFavorite>
     */
    public function list(User $user, ?string $resourceType = null, int $limit = 15): CursorPaginator
    {
        $query = UserFavorite::query()
            ->where('user_id', $user->id);

        if ($resourceType !== null) {
            $query->where('resource_type', $resourceType);
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit);
    }

    /**
     * Remove all favorites pointing to a specific resource.
     * Called on hard-delete of file/folder.
     */
    public function cleanupForResource(string $resourceType, string $resourceId): void
    {
        UserFavorite::query()
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->delete();
    }

    /**
     * Remove all favorites for multiple resources of the same type.
     *
     * @param  list<string>  $resourceIds
     */
    public function cleanupForResources(string $resourceType, array $resourceIds): void
    {
        if ($resourceIds === []) {
            return;
        }

        UserFavorite::query()
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * Remove a specific user's favorites for the given resources.
     * Used on share revoke to clean only the recipient's metadata.
     *
     * @param  list<string>  $resourceIds
     */
    public function cleanupForUser(int $userId, string $resourceType, array $resourceIds): void
    {
        if ($resourceIds === []) {
            return;
        }

        UserFavorite::query()
            ->where('user_id', $userId)
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * Validate that the resource exists and user has view access.
     */
    private function validateResourceAccess(User $user, string $resourceType, string $resourceId): void
    {
        if ($resourceType === 'file') {
            $file = File::query()->notTrashed()->find($resourceId);
            if (! $file) {
                abort(404);
            }
            Gate::forUser($user)->authorize('view', $file);
        } elseif ($resourceType === 'folder') {
            $folder = Folder::query()->notTrashed()->where('uuid', $resourceId)->first();
            if (! $folder) {
                abort(404);
            }
            Gate::forUser($user)->authorize('view', $folder);
        }
    }

    /**
     * Batch-validate that all resources exist and user has view access.
     * Loads all resources in one query, then checks permissions on each.
     * Aborts on first failure — no partial mutations.
     *
     * @param  list<string>  $resourceIds
     */
    private function validateBatchResourceAccess(User $user, string $resourceType, array $resourceIds): void
    {
        if ($resourceType === 'file') {
            $files = File::query()->notTrashed()->whereIn('id', $resourceIds)->get()->keyBy('id');
            foreach ($resourceIds as $resourceId) {
                $file = $files->get($resourceId);
                if (! $file) {
                    abort(404);
                }
                Gate::forUser($user)->authorize('view', $file);
            }
        } elseif ($resourceType === 'folder') {
            $folders = Folder::query()->notTrashed()->whereIn('uuid', $resourceIds)->get()->keyBy('uuid');
            foreach ($resourceIds as $resourceId) {
                $folder = $folders->get($resourceId);
                if (! $folder) {
                    abort(404);
                }
                Gate::forUser($user)->authorize('view', $folder);
            }
        }
    }
}
