<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\ExifService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractExifJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(private readonly string $fileId) {}

    public function handle(ExifService $exifService): void
    {
        $file = File::find($this->fileId);

        if ($file === null) {
            return;
        }

        // Already extracted — idempotent
        if ($file->exif_data !== null) {
            return;
        }

        $exifService->extractAndCache($file);
    }
}
