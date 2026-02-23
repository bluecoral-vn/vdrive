<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Share
 */
class ShareResource extends JsonResource
{
    /** @var string|null Raw token — only included on share creation */
    public ?string $rawToken = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_id' => $this->file_id,
            'folder_id' => $this->folder?->uuid,
            'shared_by' => $this->whenLoaded('sharedBy', fn () => [
                'id' => $this->sharedBy->id,
                'name' => $this->sharedBy->name,
                'email' => $this->sharedBy->email,
            ]),
            'shared_with' => $this->whenLoaded('sharedWith', fn () => [
                'id' => $this->sharedWith->id,
                'name' => $this->sharedWith->name,
                'email' => $this->sharedWith->email,
            ]),
            'permission' => $this->permission,
            'notes' => $this->notes,
            'expires_at' => $this->expires_at,
            'is_guest_link' => $this->isGuestLink(),
            'is_file_share' => $this->isFileShare(),
            'is_folder_share' => $this->isFolderShare(),
            'token' => $this->resolveToken(),
            'file' => new FileResource($this->whenLoaded('file')),
            'folder' => new FolderResource($this->whenLoaded('folder')),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Resolve token: use rawToken (set on creation), or stored encrypted token for guest links.
     */
    private function resolveToken(): ?string
    {
        if ($this->rawToken !== null) {
            return $this->rawToken;
        }

        // Only expose stored token for guest links
        if ($this->resource->isGuestLink()) {
            return $this->resource->token;
        }

        return null;
    }
}
