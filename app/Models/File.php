<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'folder_id',
        'owner_id',
        'size_bytes',
        'mime_type',
        'r2_object_key',
        'version',
        'checksum_sha256',
        'exif_data',
        'thumbnail_path',
        'thumbnail_width',
        'thumbnail_height',
        'blurhash',
        'deleted_at',
        'deleted_by',
        'purge_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
            'exif_data' => 'array',
            'thumbnail_width' => 'integer',
            'thumbnail_height' => 'integer',
            'deleted_at' => 'datetime',
            'purge_at' => 'datetime',
        ];
    }

    // ── Scopes ───────────────────────────────────────────

    /**
     * Exclude trashed files.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotTrashed(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Only trashed files.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->whereNotNull('deleted_at');
    }

    // ── Helpers ──────────────────────────────────────────

    public function isTrashed(): bool
    {
        return $this->deleted_at !== null;
    }

    // ── Relationships ────────────────────────────────────

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
