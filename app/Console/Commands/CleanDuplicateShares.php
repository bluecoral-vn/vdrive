<?php

namespace App\Console\Commands;

use App\Services\ShareService;
use Illuminate\Console\Command;

class CleanDuplicateShares extends Command
{
    protected $signature = 'shares:clean-duplicates';

    protected $description = 'Remove duplicate share rows (keeps newest per group)';

    public function handle(ShareService $shareService): int
    {
        $deleted = $shareService->cleanupDuplicateGuestLinks();

        if ($deleted === 0) {
            $this->info('No duplicate shares found.');
        } else {
            $this->info("Removed {$deleted} duplicate share(s).");
        }

        return self::SUCCESS;
    }
}
