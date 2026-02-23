<?php

namespace App\Services;

use App\Models\SyncEvent;

class SyncEventService
{
    /**
     * Record a single sync event.
     *
     * MUST be called inside the same DB transaction as the mutation.
     */
    public function record(
        int $userId,
        string $action,
        string $resourceType,
        string $resourceId,
        ?array $metadata = null,
    ): void {
        SyncEvent::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Record sync events for multiple items in a batch operation.
     *
     * MUST be called inside the same DB transaction as the mutation.
     *
     * @param  array<int, array{resource_id: string, metadata?: array<string, mixed>|null}>  $items
     */
    public function recordBatch(
        int $userId,
        string $action,
        string $resourceType,
        array $items,
    ): void {
        $now = now();

        foreach ($items as $item) {
            SyncEvent::query()->create([
                'user_id' => $userId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $item['resource_id'],
                'metadata' => $item['metadata'] ?? null,
                'created_at' => $now,
            ]);
        }
    }
}
