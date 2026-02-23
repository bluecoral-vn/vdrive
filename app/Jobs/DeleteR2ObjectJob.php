<?php

namespace App\Jobs;

use App\Services\R2ClientService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteR2ObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 600];

    public function __construct(
        public readonly string $objectKey,
    ) {}

    public function handle(R2ClientService $r2): void
    {
        if ($this->objectKey === '') {
            return;
        }

        try {
            $r2->client()->deleteObject([
                'Bucket' => $r2->bucket(),
                'Key' => $this->objectKey,
            ]);
        } catch (\Throwable $e) {
            // R2 deleteObject is idempotent (404 = already deleted)
            // Only re-throw non-404 errors for retry
            $code = $e->getCode();
            if ($code !== 404 && $code !== '404') {
                Log::warning("DeleteR2ObjectJob failed for key [{$this->objectKey}]: {$e->getMessage()}");
                throw $e;
            }
        }
    }
}
