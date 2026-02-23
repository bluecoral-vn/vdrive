<?php

namespace App\Services;

use App\Jobs\LogActivityJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserLifecycleService
{
    /**
     * Disable a user account (reversible).
     *
     * Blocks authentication and API access while preserving all data
     * and share graph integrity.
     */
    public function disableUser(User $user, string $reason, User $actor): void
    {
        DB::transaction(function () use ($user, $reason): void {
            $user->update([
                'status' => 'disabled',
                'disabled_at' => now(),
                'disabled_reason' => $reason,
                'token_version' => $user->token_version + 1,
            ]);
        });

        LogActivityJob::dispatch(
            $actor->id,
            'USER_DISABLED',
            'user',
            (string) $user->id,
            [
                'disabled_user_id' => $user->id,
                'disabled_user_email' => $user->email,
                'reason' => $reason,
            ],
            now()->toDateTimeString(),
        );
    }

    /**
     * Re-enable a disabled user account.
     */
    public function enableUser(User $user, User $actor): void
    {
        DB::transaction(function () use ($user): void {
            $user->update([
                'status' => 'active',
                'disabled_at' => null,
                'disabled_reason' => null,
            ]);
        });

        LogActivityJob::dispatch(
            $actor->id,
            'USER_RE_ENABLED',
            'user',
            (string) $user->id,
            [
                'enabled_user_id' => $user->id,
                'enabled_user_email' => $user->email,
            ],
            now()->toDateTimeString(),
        );
    }
}
