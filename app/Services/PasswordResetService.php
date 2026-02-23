<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetService
{
    /**
     * Create a password reset token for the given email.
     *
     * Stores a SHA-256 hash in the DB; returns the raw token.
     */
    public function createToken(string $email): string
    {
        $rawToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => hash('sha256', $rawToken),
                'created_at' => now(),
            ],
        );

        return $rawToken;
    }

    /**
     * Validate the token and reset the user's password.
     *
     * Returns true on success; false if the token is invalid or expired.
     */
    public function validateAndReset(string $email, string $token, string $newPassword): bool
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $record) {
            return false;
        }

        // Verify token hash matches
        if (! hash_equals($record->token, hash('sha256', $token))) {
            return false;
        }

        // Check 60-minute expiry
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            // Clean up expired token
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return false;
        }

        // Reset the password
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return false;
        }

        $user->update([
            'password' => $newPassword,
            'token_version' => $user->token_version + 1,
        ]);

        // Delete the used token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return true;
    }
}
