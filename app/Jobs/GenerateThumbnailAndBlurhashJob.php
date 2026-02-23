<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateThumbnailAndBlurhashJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly string $fileId) {}

    public function handle(ThumbnailService $thumbnailService): void
    {
        $file = File::find($this->fileId);

        if ($file === null) {
            return;
        }

        // Already generated — idempotent
        if ($file->thumbnail_path !== null) {
            return;
        }

        $thumbnailService->generateForFile($file);
    }
}
