<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DatabaseBackup extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'file_path',
        'file_size',
        'status',
        'error_message',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'expired_at' => 'datetime',
        ];
    }
}
