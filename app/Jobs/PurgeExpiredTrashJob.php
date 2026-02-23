<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\SyncEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PurgeExpiredTrashJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /**
     * Chunk size for batch processing.
     */
    private const CHUNK_SIZE = 100;

    public function handle(QuotaService $quotaService, SyncEventService $syncEventService): void
    {
        $purgedFiles = 0;
        $purgedFolders = 0;

        // Files first — chunk to avoid memory spikes
        File::query()
            ->onlyTrashed()
            ->where('purge_at', '<=', now())
            ->chunkById(self::CHUNK_SIZE, function (Collection $files) use ($quotaService, $syncEventService, &$purgedFiles) {
                /** @var Collection<int, File> $files */
                foreach ($files as $file) {
                    // Dispatch R2 deletion as separate job (idempotent)
                    DeleteR2ObjectJob::dispatch($file->r2_object_key);

                    $owner = User::query()->find($file->owner_id);
                    if ($owner) {
                        $quotaService->decrementUsage($owner, $file->size_bytes);
                    }

                    // Record sync event per file before deletion
                    $syncEventService->record(
                        $file->owner_id,
                        'purge',
                        'file',
                        (string) $file->id,
                        ['name' => $file->name, 'folder_id' => $file->folder?->uuid, 'resource_type' => 'file'],
                    );
                }

                $count = File::query()
                    ->whereIn('id', $files->pluck('id'))
                    ->delete();

                $purgedFiles += $count;
            });

        // Then folders
        Folder::query()
            ->onlyTrashed()
            ->where('purge_at', '<=', now())
            ->chunkById(self::CHUNK_SIZE, function (Collection $folders) use ($syncEventService, &$purgedFolders) {
                foreach ($folders as $folder) {
                    $syncEventService->record(
                        $folder->owner_id,
                        'purge',
                        'folder',
                        (string) $folder->uuid,
                        ['name' => $folder->name, 'parent_id' => $folder->parent?->uuid, 'resource_type' => 'folder'],
                    );
                }

                $count = Folder::query()
                    ->whereIn('id', $folders->pluck('id'))
                    ->delete();

                $purgedFolders += $count;
            });

        Log::info("PurgeExpiredTrashJob completed: {$purgedFiles} files, {$purgedFolders} folders purged.");
    }
}
