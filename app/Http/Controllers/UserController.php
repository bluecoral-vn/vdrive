<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\DeleteUserJob;
use App\Models\User;
use App\Services\DeleteUserService;
use App\Services\UserLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private UserLifecycleService $lifecycleService,
        private DeleteUserService $deleteUserService,
    ) {}

    /**
     * Display a paginated list of users.
     */
    public function index(): mixed
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->cursorPaginate(25);

        return UserResource::collection($users);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = User::query()->create($request->safe()->only(['name', 'email', 'password', 'quota_limit_bytes']));

        if ($request->validated('roles')) {
            $user->roles()->sync($request->validated('roles'));
        }

        $user->load('roles');

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        $user->load('roles');

        return new UserResource($user);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        // Regular users can only update their own name, email, password.
        // Admin-only fields (roles, quota) require 'users.update' permission.
        $fields = ['name', 'email', 'password'];
        if ($request->user()->hasPermission('users.update')) {
            $fields[] = 'quota_limit_bytes';
        }

        $user->update($request->safe()->only($fields));

        if ($request->has('roles') && $request->user()->hasPermission('users.update')) {
            $user->roles()->sync($request->validated('roles'));
        }

        $user->load('roles');

        return new UserResource($user);
    }

    /**
     * Delete a user and ALL associated data (irreversible).
     *
     * Requires `confirm: true`. Supports `async: true` for background deletion.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $request->validate([
            'confirm' => ['required', 'boolean', 'accepted'],
            'async' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('async')) {
            // Disable user first, then dispatch async deletion
            if ($user->isActive()) {
                $user->update([
                    'status' => 'disabled',
                    'disabled_at' => now(),
                    'disabled_reason' => 'Pending deletion',
                    'token_version' => $user->token_version + 1,
                ]);
            }

            DeleteUserJob::dispatch($user->id, $request->user()->id);

            return response()->json([
                'message' => 'User deletion scheduled.',
                'user_id' => $user->id,
            ], 202);
        }

        $stats = $this->deleteUserService->deleteUser($user, $request->user());

        return response()->json([
            'message' => 'User permanently deleted.',
            'stats' => $stats,
        ]);
    }

    /**
     * Disable a user account (reversible).
     */
    public function disable(Request $request, User $user): JsonResponse
    {
        $this->authorize('disable', $user);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($user->isDisabled()) {
            return response()->json(['message' => 'User is already disabled.'], 422);
        }

        $this->lifecycleService->disableUser(
            $user,
            $request->input('reason'),
            $request->user(),
        );

        $user->refresh()->load('roles');

        return (new UserResource($user))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Re-enable a disabled user account.
     */
    public function enable(Request $request, User $user): JsonResponse
    {
        $this->authorize('disable', $user);

        if ($user->isActive()) {
            return response()->json(['message' => 'User is already active.'], 422);
        }

        $this->lifecycleService->enableUser($user, $request->user());

        $user->refresh()->load('roles');

        return (new UserResource($user))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Reset a user's password (admin action).
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $request->validate([
            'new_password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)],
        ]);

        $user->update([
            'password' => $request->input('new_password'),
            'token_version' => $user->token_version + 1,
        ]);

        \App\Jobs\LogActivityJob::dispatch(
            $request->user()->id,
            'USER_PASSWORD_RESET',
            'user',
            (string) $user->id,
            [
                'target_user_id' => $user->id,
                'target_user_email' => $user->email,
            ],
            now()->toDateTimeString(),
        );

        return response()->json(['message' => 'Password has been reset.']);
    }
}
