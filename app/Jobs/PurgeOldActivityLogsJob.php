<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurgeOldActivityLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function handle(): void
    {
        $days = (int) config('app.activity_log_retention_days', 7);

        $cutoff = now()->subDays($days);

        $deleted = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        Log::info("PurgeOldActivityLogsJob completed: {$deleted} activity log(s) older than {$days} days deleted.");
    }
}
