<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class GenerateThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:generate
                            {--force : Regenerate even if thumbnail already exists}
                            {--file= : Generate for a specific file ID only}';

    protected $description = 'Generate thumbnails and blurhash for existing image files';

    public function handle(ThumbnailService $thumbnailService): int
    {
        $query = File::query()
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->whereNull('deleted_at');

        if ($fileId = $this->option('file')) {
            $query->where('id', $fileId);
        }

        if (! $this->option('force')) {
            $query->whereNull('thumbnail_path');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No files need thumbnail generation.');

            return self::SUCCESS;
        }

        $this->info("Processing {$total} file(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;
        $skipped = 0;

        $query->chunkById(50, function ($files) use ($thumbnailService, &$success, &$failed, &$skipped, $bar) {
            foreach ($files as $file) {
                if ($this->option('force') && $file->thumbnail_path !== null) {
                    // Clear existing thumbnail data to allow regeneration
                    $file->update([
                        'thumbnail_path' => null,
                        'thumbnail_width' => null,
                        'thumbnail_height' => null,
                        'blurhash' => null,
                    ]);
                }

                try {
                    $result = $thumbnailService->generateForFile($file);

                    if ($result) {
                        $success++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("  Failed: {$file->id} — {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Done! Success: {$success}, Skipped: {$skipped}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
