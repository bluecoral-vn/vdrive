<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $authUser = $request->user();
        $isAdmin = $authUser && $authUser->hasPermission('users.view-any');

        // Non-admin callers get a minimal shape for share dialogs
        if (! $isAdmin) {
            return [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'disabled_at' => $this->disabled_at,
            'disabled_reason' => $this->disabled_reason,
            'quota_limit_bytes' => $this->quota_limit_bytes !== null ? (int) $this->quota_limit_bytes : null,
            'quota_used_bytes' => (int) $this->quota_used_bytes,
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
