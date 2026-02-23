<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'recipient',
        'subject',
        'body',
        'status',
        'error_message',
        'sent_at',
        'resource_type',
        'resource_id',
        'share_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Share, $this>
     */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
