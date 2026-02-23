<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine if the user can view the list of users.
     */
    public function viewAny(User $authUser): bool
    {
        return true;
    }

    /**
     * Determine if the user can view a specific user.
     */
    public function view(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) {
            return true;
        }

        return $authUser->hasPermission('users.view');
    }

    /**
     * Determine if the user can create new users.
     */
    public function create(User $authUser): bool
    {
        return $authUser->hasPermission('users.create');
    }

    /**
     * Determine if the user can update a specific user.
     */
    public function update(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) {
            return true;
        }

        return $authUser->hasPermission('users.update');
    }

    /**
     * Determine if the user can delete a specific user.
     */
    public function delete(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) {
            return false;
        }

        return $authUser->hasPermission('users.delete');
    }

    /**
     * Determine if the user can disable/enable another user.
     */
    public function disable(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) {
            return false;
        }

        return $authUser->hasPermission('users.disable');
    }

    /**
     * Determine if the user can reset another user's password.
     */
    public function resetPassword(User $authUser, User $user): bool
    {
        if ($authUser->id === $user->id) {
            return false;
        }

        return $authUser->hasPermission('users.reset-password');
    }
}
