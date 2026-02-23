<?php

namespace App\Console\Commands;

use App\Services\UploadService;
use Illuminate\Console\Command;

class CleanupExpiredUploads extends Command
{
    protected $signature = 'uploads:cleanup';

    protected $description = 'Abort and clean up expired upload sessions';

    public function handle(UploadService $uploadService): int
    {
        $count = $uploadService->cleanupExpired();

        $this->info("Cleaned up {$count} expired upload session(s).");

        return self::SUCCESS;
    }
}
