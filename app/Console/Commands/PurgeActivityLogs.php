<?php

namespace App\Console\Commands;

use App\Jobs\PurgeOldActivityLogsJob;
use App\Models\ActivityLog;
use Illuminate\Console\Command;

class PurgeActivityLogs extends Command
{
    protected $signature = 'activity:purge {--dispatch : Dispatch as a background job instead of running inline}';

    protected $description = 'Delete activity logs older than the configured retention period';

    public function handle(): int
    {
        if ($this->option('dispatch')) {
            PurgeOldActivityLogsJob::dispatch();
            $this->info('Activity log purge job dispatched.');

            return self::SUCCESS;
        }

        $days = (int) config('app.activity_log_retention_days', 7);
        $cutoff = now()->subDays($days);

        $this->info("Purging activity logs older than {$days} days...");

        $deleted = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Purged {$deleted} activity log(s).");

        return self::SUCCESS;
    }
}
