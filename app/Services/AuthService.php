<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    private const REMEMBER_TTL_MINUTES = 43200; // 30 days

    /**
     * Attempt to authenticate and return a JWT token.
     */
    public function login(string $email, string $password, bool $remember = false): string|false
    {
        // Check if user exists and is active before attempting auth
        $user = User::query()->where('email', $email)->first();

        if ($user && ! $user->isActive()) {
            return false;
        }

        if ($remember) {
            auth()->factory()->setTTL(self::REMEMBER_TTL_MINUTES);
        }

        $token = Auth::attempt([
            'email' => $email,
            'password' => $password,
        ]);

        if (! $token) {
            return false;
        }

        return $token;
    }

    /**
     * Refresh the current JWT token.
     */
    public function refresh(): string
    {
        return Auth::refresh();
    }

    /**
     * Invalidate the current JWT token.
     */
    public function logout(): void
    {
        Auth::logout();
    }
}
