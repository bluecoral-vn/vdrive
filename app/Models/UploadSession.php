<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSession extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'folder_id',
        'filename',
        'mime_type',
        'size_bytes',
        'r2_object_key',
        'r2_upload_id',
        'status',
        'total_parts',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'total_parts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
