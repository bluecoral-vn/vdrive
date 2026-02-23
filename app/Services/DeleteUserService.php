<?php

namespace App\Services;

use App\Jobs\DeleteR2ObjectJob;
use App\Jobs\LogActivityJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteUserService
{
    public function __construct(
        private QuotaService $quotaService,
    ) {}

    /**
     * Hard-delete a user and ALL associated data (irreversible).
     *
     * Deletion order (leaf-first):
     *  1. Increment token_version (instant JWT invalidation)
     *  2. Cancel pending jobs
     *  3. Delete files + R2 objects (leaf nodes first)
     *  4. Delete shares
     *  5. Delete folders (deepest-first)
     *  6. Delete activity logs, role pivots, user row
     *
     * @return array{files_deleted: int, folders_deleted: int, shares_deleted: int, r2_objects_deleted: int}
     */
    public function deleteUser(User $user, User $actor): array
    {
        $stats = [
            'files_deleted' => 0,
            'folders_deleted' => 0,
            'shares_deleted' => 0,
            'r2_objects_deleted' => 0,
        ];

        // Step 1 — Invalidate all JWT tokens immediately
        $user->update(['token_version' => $user->token_version + 1]);

        // Step 2 — Cancel pending background jobs for this user
        $this->cancelPendingJobs($user);

        // Step 3 — Delete files + R2 objects (leaf-first)
        $stats = $this->deleteUserFiles($user, $stats);

        // Step 4 — Delete shares
        $stats['shares_deleted'] = $this->deleteUserShares($user);

        // Step 5 — Delete folders (deepest-first)
        $stats['folders_deleted'] = $this->deleteUserFolders($user);

        // Step 6 — Delete remaining records + user row
        DB::transaction(function () use ($user): void {
            // Activity logs
            ActivityLog::query()->where('user_id', $user->id)->delete();

            // Role pivots
            $user->roles()->detach();

            // User row
            $user->delete();
        });

        // Log the deletion (actor's log, not the deleted user's)
        LogActivityJob::dispatch(
            $actor->id,
            'USER_DELETED',
            'user',
            (string) $user->id,
            [
                'deleted_user_id' => $user->id,
                'deleted_user_email' => $user->email,
                'stats' => $stats,
            ],
            now()->toDateTimeString(),
        );

        return $stats;
    }

    /**
     * Delete all files owned by the user, including R2 objects.
     *
     * @param  array{files_deleted: int, folders_deleted: int, shares_deleted: int, r2_objects_deleted: int}  $stats
     * @return array{files_deleted: int, folders_deleted: int, shares_deleted: int, r2_objects_deleted: int}
     */
    private function deleteUserFiles(User $user, array $stats): array
    {
        // Include soft-deleted files
        File::query()
            ->where('owner_id', $user->id)
            ->chunkById(200, function (Collection $files) use (&$stats): void {
                /** @var Collection<int, File> $files */
                foreach ($files as $file) {
                    // Dispatch R2 deletion (idempotent, 404-tolerant)
                    if ($file->r2_object_key) {
                        DeleteR2ObjectJob::dispatch($file->r2_object_key);
                        $stats['r2_objects_deleted']++;
                    }

                    $stats['files_deleted']++;
                }

                // Bulk delete file rows
                File::query()
                    ->whereIn('id', $files->pluck('id'))
                    ->delete();
            });

        return $stats;
    }

    /**
     * Delete all shares involving the user (as sharer or recipient).
     */
    private function deleteUserShares(User $user): int
    {
        return Share::query()
            ->where('shared_by', $user->id)
            ->orWhere('shared_with', $user->id)
            ->delete();
    }

    /**
     * Delete all folders owned by the user (deepest-first by path length).
     */
    private function deleteUserFolders(User $user): int
    {
        // Get all folder IDs sorted by path length DESC (deepest first)
        $folderIds = Folder::query()
            ->where('owner_id', $user->id)
            ->orderByRaw('LENGTH(COALESCE(path, \'\')) DESC')
            ->pluck('id');

        if ($folderIds->isEmpty()) {
            return 0;
        }

        // Delete in chunks, deepest first
        $deleted = 0;
        foreach ($folderIds->chunk(200) as $chunk) {
            $deleted += Folder::query()
                ->whereIn('id', $chunk)
                ->delete();
        }

        return $deleted;
    }

    /**
     * Cancel pending background jobs for the user.
     */
    private function cancelPendingJobs(User $user): void
    {
        try {
            DB::table('jobs')
                ->where('payload', 'LIKE', '%"userId":'.$user->id.'%')
                ->orWhere('payload', 'LIKE', '%"owner_id":'.$user->id.'%')
                ->delete();
        } catch (\Throwable $e) {
            // Jobs table may not exist or have different structure
            Log::warning("Could not cancel jobs for user {$user->id}: {$e->getMessage()}");
        }
    }
}
