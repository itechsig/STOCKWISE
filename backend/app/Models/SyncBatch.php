<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncBatch extends Model
{
    protected $fillable = [
        'sync_code', 'source', 'started_at', 'finished_at', 'status', 'current_step',
        'total_records', 'inserted_records', 'updated_records', 'skipped_records',
        'deleted_records', 'error_records', 'error_message', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function durationSeconds(): ?float
    {
        if (! $this->finished_at) {
            return null;
        }

        return $this->started_at->diffInMilliseconds($this->finished_at) / 1000;
    }
}
