<?php

namespace App\Services;

use App\Models\User;

class QuotaService
{
    /**
     * Get user's quota information.
     *
     * @return array{limit_bytes: int|null, used_bytes: int, remaining_bytes: int|null}
     */
    public function getQuota(User $user): array
    {
        $limit = $user->quota_limit_bytes;
        $used = $user->quota_used_bytes ?? 0;

        return [
            'limit_bytes' => $limit,
            'used_bytes' => $used,
            'remaining_bytes' => $limit !== null ? max(0, $limit - $used) : null,
        ];
    }

    /**
     * Check if adding newBytes would exceed user's quota.
     */
    public function checkQuota(User $user, int $newBytes): bool
    {
        $limit = $user->quota_limit_bytes;

        if ($limit === null) {
            return true; // no limit
        }

        return ($user->quota_used_bytes + $newBytes) <= $limit;
    }

    /**
     * Increment user's used storage after successful upload.
     */
    public function incrementUsage(User $user, int $bytes): void
    {
        $user->increment('quota_used_bytes', $bytes);
    }

    /**
     * Decrement user's used storage after file deletion.
     */
    public function decrementUsage(User $user, int $bytes): void
    {
        $user->decrement('quota_used_bytes', min($bytes, $user->quota_used_bytes));
    }
}
