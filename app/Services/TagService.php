<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class TagService
{
    /**
     * Create a new tag for the user.
     */
    public function createTag(User $user, string $name, ?string $color = null): Tag
    {
        $exists = Tag::query()
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            abort(422, 'A tag with this name already exists.');
        }

        return Tag::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'color' => $color,
        ]);
    }

    /**
     * Update a tag.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTag(Tag $tag, array $data): Tag
    {
        // Check for duplicate name when renaming
        if (isset($data['name']) && $data['name'] !== $tag->name) {
            $exists = Tag::query()
                ->where('user_id', $tag->user_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $tag->id)
                ->exists();

            if ($exists) {
                abort(422, 'A tag with this name already exists.');
            }
        }

        $tag->update($data);

        return $tag->fresh();
    }

    /**
     * Delete a tag. Cascade removes taggables via FK.
     */
    public function deleteTag(Tag $tag): void
    {
        $tag->delete();
    }

    /**
     * List all tags for a user (not paginated — typically few per user).
     *
     * @return Collection<int, Tag>
     */
    public function listTags(User $user): Collection
    {
        return Tag::query()
            ->where('user_id', $user->id)
            ->withCount('taggables')
            ->orderBy('name')
            ->get();
    }

    /**
     * Bulk assign tags to resources.
     *
     * @param  list<string>  $tagUuids
     * @param  list<string>  $resourceIds
     */
    public function assign(User $user, array $tagUuids, string $resourceType, array $resourceIds): void
    {
        // Validate all tags belong to the user (resolve UUID → internal ID)
        $tags = Tag::query()
            ->where('user_id', $user->id)
            ->whereIn('uuid', $tagUuids)
            ->get();

        if ($tags->count() !== count($tagUuids)) {
            abort(422, 'One or more tags not found.');
        }

        // Batch-validate resource access (avoids N+1)
        $this->validateBatchResourceAccess($user, $resourceType, $resourceIds);

        // Create taggable records (idempotent via firstOrCreate)
        foreach ($tags as $tag) {
            foreach ($resourceIds as $resourceId) {
                Taggable::query()->firstOrCreate([
                    'tag_id' => $tag->id,
                    'resource_type' => $resourceType,
                    'resource_id' => (string) $resourceId,
                ]);
            }
        }
    }

    /**
     * Bulk unassign tags from resources.
     *
     * @param  list<string>  $tagUuids
     * @param  list<string>  $resourceIds
     */
    public function unassign(User $user, array $tagUuids, string $resourceType, array $resourceIds): void
    {
        // Validate all tags belong to the user (resolve UUID → internal ID)
        $tags = Tag::query()
            ->where('user_id', $user->id)
            ->whereIn('uuid', $tagUuids)
            ->get();

        if ($tags->count() !== count($tagUuids)) {
            abort(422, 'One or more tags not found.');
        }

        $tagIds = $tags->pluck('id')->all();

        Taggable::query()
            ->whereIn('tag_id', $tagIds)
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * List items with a given tag (cursor-paginated).
     * Returns separate file and folder results.
     * Permission-scoped: only returns resources the user can access.
     *
     * @return array{files: CursorPaginator<File>, folders: CursorPaginator<Folder>}
     */
    public function tagItems(Tag $tag, User $user, int $limit = 15): array
    {
        $fileIds = Taggable::query()
            ->where('tag_id', $tag->id)
            ->where('resource_type', 'file')
            ->pluck('resource_id');

        $fileQuery = File::query()
            ->notTrashed()
            ->whereIn('id', $fileIds);

        // Permission scoping for files (mirrors SearchService)
        if (! $user->hasPermission('files.view-any')) {
            $fileQuery->where(function (Builder $q) use ($user) {
                $q->where('owner_id', $user->id);

                $directFileIds = Share::query()
                    ->whereNotNull('file_id')
                    ->where('shared_with', $user->id)
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('file_id');

                if ($directFileIds->isNotEmpty()) {
                    $q->orWhereIn('id', $directFileIds);
                }

                $sharedFolderPaths = Share::query()
                    ->whereNotNull('folder_id')
                    ->where('shared_with', $user->id)
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->join('folders', 'shares.folder_id', '=', 'folders.id')
                    ->pluck('folders.path');

                if ($sharedFolderPaths->isNotEmpty()) {
                    $q->orWhereHas('folder', function (Builder $folderQuery) use ($sharedFolderPaths) {
                        $folderQuery->where(function (Builder $pathQuery) use ($sharedFolderPaths) {
                            foreach ($sharedFolderPaths as $sharedPath) {
                                $pathQuery->orWhere('path', 'LIKE', $sharedPath.'%');
                            }
                        });
                    });
                }
            });
        }

        $files = $fileQuery
            ->with('folder')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit, ['*'], 'file_cursor');

        $folderIds = Taggable::query()
            ->where('tag_id', $tag->id)
            ->where('resource_type', 'folder')
            ->pluck('resource_id');

        $folderQuery = Folder::query()
            ->notTrashed()
            ->whereIn('uuid', $folderIds);

        // Permission scoping for folders
        if (! $user->hasPermission('files.view-any')) {
            $folderQuery->where(function (Builder $q) use ($user) {
                $q->where('owner_id', $user->id);

                $directFolderIds = Share::query()
                    ->whereNotNull('folder_id')
                    ->where('shared_with', $user->id)
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('folder_id');

                if ($directFolderIds->isNotEmpty()) {
                    $q->orWhereIn('id', $directFolderIds);
                }

                $sharedFolderPaths = Share::query()
                    ->whereNotNull('folder_id')
                    ->where('shared_with', $user->id)
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->join('folders', 'shares.folder_id', '=', 'folders.id')
                    ->pluck('folders.path');

                if ($sharedFolderPaths->isNotEmpty()) {
                    $q->orWhere(function (Builder $pathQuery) use ($sharedFolderPaths) {
                        foreach ($sharedFolderPaths as $sharedPath) {
                            $pathQuery->orWhere('path', 'LIKE', $sharedPath.'%');
                        }
                    });
                }
            });
        }

        $folders = $folderQuery
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit, ['*'], 'folder_cursor');

        return [
            'files' => $files,
            'folders' => $folders,
        ];
    }

    /**
     * Remove all taggables pointing to a specific resource.
     * Called on hard-delete of file/folder.
     */
    public function cleanupForResource(string $resourceType, string $resourceId): void
    {
        Taggable::query()
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->delete();
    }

    /**
     * Remove all taggables for multiple resources of the same type.
     *
     * @param  list<string>  $resourceIds
     */
    public function cleanupForResources(string $resourceType, array $resourceIds): void
    {
        if ($resourceIds === []) {
            return;
        }

        Taggable::query()
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * Remove a specific user's tag relations for the given resources.
     * Used on share revoke to clean only the recipient's metadata.
     *
     * @param  list<string>  $resourceIds
     */
    public function cleanupForUser(int $userId, string $resourceType, array $resourceIds): void
    {
        if ($resourceIds === []) {
            return;
        }

        $tagIds = Tag::query()->where('user_id', $userId)->pluck('id');

        if ($tagIds->isEmpty()) {
            return;
        }

        Taggable::query()
            ->whereIn('tag_id', $tagIds)
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    /**
     * Batch-validate that all resources exist and user has view access.
     * Uses whereIn to load all resources in one query, then checks permissions.
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
