<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserActive
{
    /**
     * Reject requests from disabled/suspended users and stale JWT tokens.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Block disabled/suspended users
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Account disabled.',
            ], 403);
        }

        // Reject stale JWT tokens (token_version mismatch)
        // Skip when using actingAs() in tests (no real JWT payload)
        try {
            $payload = auth('api')->payload();
            $tokenVersion = $payload->get('tv');

            if ($tokenVersion !== null && (int) $tokenVersion !== $user->token_version) {
                auth('api')->invalidate();

                return response()->json([
                    'message' => 'Token revoked.',
                ], 401);
            }
        } catch (\Throwable) {
            // No JWT payload available (e.g. actingAs in tests) — skip version check
        }

        return $next($request);
    }
}
