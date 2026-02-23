<?php

namespace App\Http\Resources;

use App\Models\File;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\UserFavorite
 */
class FavoriteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = null;

        if ($this->resource_type === 'file') {
            $file = File::query()->notTrashed()->find($this->resource_id);
            if ($file) {
                $resource = new FileResource($file);
            }
        } elseif ($this->resource_type === 'folder') {
            $folder = Folder::query()->notTrashed()->where('uuid', $this->resource_id)->first();
            if ($folder) {
                $resource = new FolderResource($folder);
            }
        }

        return [
            'id' => $this->id,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'created_at' => $this->created_at,
            'resource' => $resource,
        ];
    }
}
