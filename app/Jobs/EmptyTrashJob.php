<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\Folder;
use App\Models\Taggable;
use App\Models\User;
use App\Models\UserFavorite;
use App\Services\QuotaService;
use App\Services\SyncEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EmptyTrashJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    private const CHUNK_SIZE = 100;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(QuotaService $quotaService, SyncEventService $syncEventService): void
    {
        $purgedFiles = 0;
        $purgedFolders = 0;

        // Files first — chunk to avoid memory spikes
        File::query()
            ->onlyTrashed()
            ->where('owner_id', $this->userId)
            ->chunkById(self::CHUNK_SIZE, function (Collection $files) use ($quotaService, $syncEventService, &$purgedFiles) {
                /** @var Collection<int, File> $files */
                $fileItems = [];
                foreach ($files as $file) {
                    DeleteR2ObjectJob::dispatch($file->r2_object_key);

                    // Also delete thumbnail from R2 if exists
                    if ($file->thumbnail_path !== null && $file->thumbnail_path !== '') {
                        DeleteR2ObjectJob::dispatch($file->thumbnail_path);
                    }

                    $owner = User::query()->find($file->owner_id);
                    if ($owner) {
                        $quotaService->decrementUsage($owner, $file->size_bytes);
                    }

                    $fileItems[] = [
                        'resource_id' => (string) $file->id,
                        'metadata' => ['name' => $file->name, 'folder_id' => $file->folder_id, 'resource_type' => 'file'],
                    ];
                }

                // Record sync events before deletion
                if ($fileItems !== []) {
                    $syncEventService->recordBatch(
                        $this->userId,
                        'purge',
                        'file',
                        $fileItems,
                    );
                }

                // Cleanup favorites and taggables for these files
                $fileIdStrings = $files->pluck('id')->map(fn ($id) => (string) $id)->all();
                UserFavorite::query()->where('resource_type', 'file')->whereIn('resource_id', $fileIdStrings)->delete();
                Taggable::query()->where('resource_type', 'file')->whereIn('resource_id', $fileIdStrings)->delete();

                $count = File::query()
                    ->whereIn('id', $files->pluck('id'))
                    ->delete();

                $purgedFiles += $count;
            });

        // Then folders
        Folder::query()
            ->onlyTrashed()
            ->where('owner_id', $this->userId)
            ->chunkById(self::CHUNK_SIZE, function (Collection $folders) use ($syncEventService, &$purgedFolders) {
                $folderItems = [];
                foreach ($folders as $folder) {
                    $folderItems[] = [
                        'resource_id' => (string) $folder->id,
                        'metadata' => ['name' => $folder->name, 'parent_id' => $folder->parent_id, 'resource_type' => 'folder'],
                    ];
                }

                // Record sync events before deletion
                if ($folderItems !== []) {
                    $syncEventService->recordBatch(
                        $this->userId,
                        'purge',
                        'folder',
                        $folderItems,
                    );
                }

                // Cleanup favorites and taggables for these folders
                $folderIdStrings = $folders->pluck('id')->map(fn ($id) => (string) $id)->all();
                UserFavorite::query()->where('resource_type', 'folder')->whereIn('resource_id', $folderIdStrings)->delete();
                Taggable::query()->where('resource_type', 'folder')->whereIn('resource_id', $folderIdStrings)->delete();

                $count = Folder::query()
                    ->whereIn('id', $folders->pluck('id'))
                    ->delete();

                $purgedFolders += $count;
            });

        Log::info("EmptyTrashJob completed for user {$this->userId}: {$purgedFiles} files, {$purgedFolders} folders purged.");
    }
}
