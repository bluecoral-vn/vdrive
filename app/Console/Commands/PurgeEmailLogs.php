<?php

namespace App\Console\Commands;

use App\Jobs\PurgeOldEmailLogsJob;
use App\Models\EmailLog;
use Illuminate\Console\Command;

class PurgeEmailLogs extends Command
{
    protected $signature = 'email-logs:purge {--dispatch : Dispatch as a background job instead of running inline}';

    protected $description = 'Delete email logs older than the configured retention period';

    public function handle(): int
    {
        if ($this->option('dispatch')) {
            PurgeOldEmailLogsJob::dispatch();
            $this->info('Email log purge job dispatched.');

            return self::SUCCESS;
        }

        $days = (int) config('app.email_log_retention_days', 7);
        $cutoff = now()->subDays($days);

        $this->info("Purging email logs older than {$days} days...");

        $deleted = EmailLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Purged {$deleted} email log(s).");

        return self::SUCCESS;
    }
}
