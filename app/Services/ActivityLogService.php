<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ActivityLogService
{
    /**
     * Record an activity log entry.
     */
    public function log(
        ?int $userId,
        string $action,
        string $resourceType,
        string $resourceId,
        ?array $metadata = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => (string) $resourceId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * Get paginated activity logs for a specific user.
     *
     * @return CursorPaginator<ActivityLog>
     */
    public function forUser(User $user, int $limit = 15): CursorPaginator
    {
        return ActivityLog::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit);
    }

    /**
     * Get paginated activity logs (all users — admin only).
     *
     * @return CursorPaginator<ActivityLog>
     */
    public function all(int $limit = 15): CursorPaginator
    {
        return ActivityLog::query()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate($limit);
    }
}
