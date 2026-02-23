<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

class FolderService
{
    public function __construct(
        private SyncEventService $syncEventService,
    ) {}

    /**
     * Create a new folder and record sync event.
     */
    public function create(User $owner, string $name, ?int $parentId = null): Folder
    {
        return DB::transaction(function () use ($owner, $name, $parentId): Folder {
            $folder = Folder::query()->create([
                'name' => $name,
                'parent_id' => $parentId,
                'owner_id' => $owner->id,
            ]);

            $this->syncEventService->record(
                $owner->id,
                'create',
                'folder',
                (string) $folder->uuid,
                ['name' => $folder->name, 'parent_id' => $folder->parent?->uuid, 'resource_type' => 'folder'],
            );

            return $folder;
        });
    }

    /**
     * Rename a folder and record sync event.
     */
    public function rename(Folder $folder, string $newName): Folder
    {
        return DB::transaction(function () use ($folder, $newName): Folder {
            $oldName = $folder->name;

            $folder->update(['name' => $newName]);

            $this->syncEventService->record(
                $folder->owner_id,
                'rename',
                'folder',
                (string) $folder->uuid,
                ['name' => $folder->name, 'old_name' => $oldName, 'resource_type' => 'folder'],
            );

            return $folder;
        });
    }

    /**
     * Get paginated children of a folder (or root folders for a user).
     * Excludes trashed items.
     *
     * @return CursorPaginator<Folder>
     */
    public function children(int $folderId, int $limit = 15): CursorPaginator
    {
        return Folder::query()
            ->notTrashed()
            ->where('parent_id', $folderId)
            ->orderBy('name')
            ->cursorPaginate($limit);
    }

    /**
     * Get paginated root folders for a user.
     * Excludes trashed items.
     *
     * @return CursorPaginator<Folder>
     */
    public function rootFolders(int $ownerId, int $limit = 15): CursorPaginator
    {
        return Folder::query()
            ->notTrashed()
            ->whereNull('parent_id')
            ->where('owner_id', $ownerId)
            ->orderBy('name')
            ->cursorPaginate($limit);
    }

    /**
     * Delete a folder and all descendants (cascaded via FK).
     */
    public function delete(Folder $folder): void
    {
        $folder->delete();
    }

    /**
     * Collect ancestor folder IDs by walking the parent_id chain upward.
     * Iterative (no recursion), capped at 50 levels.
     *
     * @return list<int>
     */
    public function getAncestorIds(?int $folderId): array
    {
        if ($folderId === null) {
            return [];
        }

        $ancestors = [];
        $currentId = $folderId;
        $maxDepth = 50;

        while ($currentId !== null && $maxDepth-- > 0) {
            $ancestors[] = $currentId;

            $currentId = Folder::query()
                ->where('id', $currentId)
                ->value('parent_id');
        }

        return $ancestors;
    }

    /**
     * Collect all descendant folder IDs using BFS (no recursive queries).
     *
     * @return list<int>
     */
    public function getDescendantFolderIds(int $parentId): array
    {
        $allIds = [];
        $queue = [$parentId];

        while ($queue !== []) {
            $currentBatch = $queue;
            $queue = [];

            $childIds = Folder::query()
                ->notTrashed()
                ->whereIn('parent_id', $currentBatch)
                ->pluck('id')
                ->all();

            foreach ($childIds as $childId) {
                $allIds[] = $childId;
                $queue[] = $childId;
            }
        }

        return $allIds;
    }
}
