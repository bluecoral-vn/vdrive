<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DeleteUserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes for large accounts

    public function __construct(
        public readonly int $userId,
        public readonly int $actorId,
    ) {}

    public function handle(DeleteUserService $service): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            Log::info("DeleteUserJob: user {$this->userId} already deleted, skipping.");

            return;
        }

        $actor = User::query()->find($this->actorId);

        if (! $actor) {
            Log::error("DeleteUserJob: actor {$this->actorId} not found, aborting.");

            return;
        }

        $stats = $service->deleteUser($user, $actor);

        Log::info("DeleteUserJob completed for user {$this->userId}", $stats);
    }
}
