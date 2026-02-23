<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
        'quota_limit_bytes',
        'quota_used_bytes',
        'status',
        'disabled_at',
        'disabled_reason',
        'token_version',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'quota_limit_bytes' => 'integer',
            'quota_used_bytes' => 'integer',
            'disabled_at' => 'datetime',
            'token_version' => 'integer',
        ];
    }

    // ── Status Helpers ───────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }

    // ── Scopes ───────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // ── Relationships ────────────────────────────────────

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'owner_id');
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'owner_id');
    }

    /**
     * Check if the user has a specific permission through any of their roles.
     */
    public function hasPermission(string $slug): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($slug): void {
                $query->where('slug', $slug);
            })
            ->exists();
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'tv' => $this->token_version,
        ];
    }
}
