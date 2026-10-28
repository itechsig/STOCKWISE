<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sync_batch_id', 'entity', 'source_id', 'action', 'status', 'message',
        'old_data', 'new_data', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_data' => 'array',
            'new_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }
}
