<?php

namespace App\Console\Commands;

use App\Jobs\PurgeExpiredTrashJob;
use App\Services\TrashService;
use Illuminate\Console\Command;

class PurgeTrash extends Command
{
    protected $signature = 'trash:purge {--dispatch : Dispatch as a background job instead of running inline}';

    protected $description = 'Permanently delete trash items past their purge date';

    public function handle(TrashService $trashService): int
    {
        if ($this->option('dispatch')) {
            PurgeExpiredTrashJob::dispatch();
            $this->info('Trash purge job dispatched.');

            return self::SUCCESS;
        }

        $this->info('Starting trash purge...');

        $purged = $trashService->purgeExpired();

        $this->info("Purged {$purged} expired item(s).");

        return self::SUCCESS;
    }
}
