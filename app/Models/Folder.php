<?php

namespace App\Models;

use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'uuid',
        'parent_id',
        'owner_id',
        'path',
        'deleted_at',
        'deleted_by',
        'purge_at',
    ];

    /**
     * Resolve route model binding by UUID.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'purge_at' => 'datetime',
        ];
    }

    // ── Boot ─────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Folder $folder): void {
            if (empty($folder->uuid)) {
                $folder->uuid = (string) Str::uuid();
            }
            if ($folder->path === '' || $folder->path === null) {
                $folder->path = self::computePath($folder->parent_id, $folder->id);
            }
        });

        static::created(function (Folder $folder): void {
            // After creation we have the real ID — recompute if path was placeholder
            $correctPath = self::computePath($folder->parent_id, $folder->id);
            if ($folder->path !== $correctPath) {
                $folder->updateQuietly(['path' => $correctPath]);
            }
        });
    }

    /**
     * Compute the materialized path for a folder.
     */
    public static function computePath(?int $parentId, ?int $folderId): string
    {
        if ($parentId === null) {
            return $folderId !== null ? '/'.$folderId.'/' : '/';
        }

        $parentPath = self::query()->where('id', $parentId)->value('path');

        return ($parentPath ?? '/').$folderId.'/';
    }

    // ── Scopes ───────────────────────────────────────────

    /**
     * Exclude trashed folders.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotTrashed(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Only trashed folders.
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
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
