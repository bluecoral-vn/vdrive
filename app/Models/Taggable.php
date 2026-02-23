<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Taggable extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'tag_id',
        'resource_type',
        'resource_id',
    ];

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
