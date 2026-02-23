<?php

namespace App\Services;

use App\Models\File;
use App\Models\Share;
use App\Models\Taggable;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class SearchService
{
    /**
     * Search files with filters, respecting permission model.
     *
     * Permission scoping:
     * - Admin (files.view-any): sees all non-trashed files
     * - Regular user: sees owned files + directly shared + inherited folder share (path-based)
     *
     * No BFS descendant walk — uses materialized path prefix matching.
     *
     * @return CursorPaginator<File>
     */
    public function search(
        User $user,
        ?string $query = null,
        string|array|null $mime = null,
        ?string $from = null,
        ?string $to = null,
        ?int $ownerId = null,
        int $limit = 15,
        ?int $tagId = null,
        bool $favoritesOnly = false,
    ): CursorPaginator {
        $builder = File::query()->notTrashed();

        // ── Permission scoping ──────────────────────────────
        if (! $user->hasPermission('files.view-any')) {
            $builder->where(function (Builder $q) use ($user) {
                // 1. Owned files
                $q->where('owner_id', $user->id);

                // 2. Directly shared files
                $directFileIds = Share::query()
                    ->whereNotNull('file_id')
                    ->where('shared_with', $user->id)
                    ->where(fn ($sq) => $sq->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->pluck('file_id');

                if ($directFileIds->isNotEmpty()) {
                    $q->orWhereIn('id', $directFileIds);
                }

                // 3. Files in shared folders (path-based — no BFS)
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

        // ── Filters ─────────────────────────────────────────
        if ($query !== null && $query !== '') {
            $builder->where('name', 'LIKE', '%'.$query.'%');
        }

        if (is_array($mime)) {
            $filtered = array_filter($mime, fn ($m) => $m !== null && $m !== '');
            if ($filtered !== []) {
                $builder->whereIn('mime_type', $filtered);
            }
        } elseif ($mime !== null && $mime !== '') {
            $builder->where('mime_type', $mime);
        }

        if ($from !== null && $from !== '') {
            $builder->where('created_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $builder->where('created_at', '<=', $to);
        }

        if ($ownerId !== null) {
            $builder->where('owner_id', $ownerId);
        }

        // ── Tag filter ──────────────────────────────────────
        if ($tagId !== null) {
            $taggedFileIds = Taggable::query()
                ->where('tag_id', $tagId)
                ->where('resource_type', 'file')
                ->pluck('resource_id');

            $builder->whereIn('id', $taggedFileIds);
        }

        // ── Favorites filter ────────────────────────────────
        if ($favoritesOnly) {
            $favFileIds = UserFavorite::query()
                ->where('user_id', $user->id)
                ->where('resource_type', 'file')
                ->pluck('resource_id');

            $builder->whereIn('id', $favFileIds);
        }

        return $builder
            ->with('folder')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit);
    }
}
