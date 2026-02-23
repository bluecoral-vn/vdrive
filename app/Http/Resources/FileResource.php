<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\File
 */
class FileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'folder_id' => $this->folder?->uuid,
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
            ]),
            'size_bytes' => (int) $this->size_bytes,
            'mime_type' => $this->mime_type,
            'version' => (int) $this->version,
            'checksum_sha256' => $this->checksum_sha256,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'exif' => $this->exif_data,
            'thumbnail_width' => $this->thumbnail_width !== null ? (int) $this->thumbnail_width : null,
            'thumbnail_height' => $this->thumbnail_height !== null ? (int) $this->thumbnail_height : null,
            'blurhash' => $this->blurhash,
            'preview_supported' => in_array($this->mime_type, [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
                'video/mp4', 'video/webm',
                'application/pdf',
            ], true),

            $this->mergeWhen($this->deleted_at !== null, [
                'deleted_at' => $this->deleted_at,
                'purge_at' => $this->purge_at,
            ]),
        ];
    }
}
