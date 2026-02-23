<?php

namespace App\Console\Commands;

use App\Jobs\DeleteR2ObjectJob;
use App\Models\File;
use Illuminate\Console\Command;

class ClearThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:clear
                            {--file= : Clear thumbnail for a specific file ID only}';

    protected $description = 'Delete all existing thumbnails from R2 and reset database records';

    public function handle(): int
    {
        $query = File::query()
            ->whereNotNull('thumbnail_path')
            ->whereNull('deleted_at');

        if ($fileId = $this->option('file')) {
            $query->where('id', $fileId);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No thumbnails to clear.');

            return self::SUCCESS;
        }

        if (! $this->confirm("This will delete {$total} thumbnail(s) from R2 and reset DB records. Continue?")) {
            return self::SUCCESS;
        }

        $this->info("Clearing {$total} thumbnail(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $deleted = 0;

        $query->chunkById(50, function ($files) use (&$deleted, $bar) {
            foreach ($files as $file) {
                // Dispatch R2 delete job
                DeleteR2ObjectJob::dispatch($file->thumbnail_path);

                // Reset DB record
                $file->update([
                    'thumbnail_path' => null,
                    'thumbnail_width' => null,
                    'thumbnail_height' => null,
                    'blurhash' => null,
                ]);

                $deleted++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Done! Cleared {$deleted} thumbnail(s). Run `php artisan thumbnails:generate` to regenerate.");

        return self::SUCCESS;
    }
}
