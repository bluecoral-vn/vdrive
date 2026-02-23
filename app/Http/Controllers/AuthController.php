<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendPasswordResetJob;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private PasswordResetService $passwordResetService,
    ) {}

    /**
     * Authenticate user and return JWT token.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
            (bool) $request->validated('remember', false),
        );

        if ($token === false) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Request a password reset link via email.
     *
     * Always returns the same message to prevent email enumeration.
     *
     * @unauthenticated
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = $request->input('email');
        $user = User::query()->where('email', $email)->first();

        // Only send if the user exists and is active
        if ($user && $user->isActive()) {
            $rawToken = $this->passwordResetService->createToken($email);

            $appUrl = config('app.url', 'http://localhost');
            $resetUrl = $appUrl.'/reset-password?token='.urlencode($rawToken).'&email='.urlencode($email);

            SendPasswordResetJob::dispatch(
                email: $email,
                recipientName: $user->name,
                resetUrl: $resetUrl,
            );
        }

        return response()->json([
            'message' => 'If an account with that email exists, a reset link has been sent.',
        ]);
    }

    /**
     * Reset password using a valid token.
     *
     * @unauthenticated
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $success = $this->passwordResetService->validateAndReset(
            $request->input('email'),
            $request->input('token'),
            $request->input('password'),
        );

        if (! $success) {
            return response()->json([
                'message' => 'Invalid or expired reset token.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password has been reset successfully.',
        ]);
    }

    /**
     * Refresh the current JWT token.
     */
    public function refresh(): JsonResponse
    {
        $token = $this->authService->refresh();

        return $this->respondWithToken($token);
    }

    /**
     * Invalidate the current JWT token.
     */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return response()->json(['message' => 'Successfully logged out.']);
    }

    /**
     * Get the authenticated user.
     */
    public function me(): UserResource
    {
        $user = auth()->user();
        $user->load('roles');

        return new UserResource($user);
    }

    /**
     * Structure the token response.
     */
    private function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
        ]);
    }
}
