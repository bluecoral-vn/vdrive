<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly ?int $userId,
        public readonly string $action,
        public readonly string $resourceType,
        public readonly string $resourceId,
        public readonly ?array $metadata,
        public readonly string $occurredAt,
    ) {}

    public function handle(): void
    {
        // Idempotent: prevent duplicate logs on retry
        ActivityLog::query()->firstOrCreate(
            [
                'user_id' => $this->userId,
                'action' => $this->action,
                'resource_type' => $this->resourceType,
                'resource_id' => $this->resourceId,
                'created_at' => $this->occurredAt,
            ],
            [
                'metadata' => $this->metadata,
            ],
        );
    }
}
