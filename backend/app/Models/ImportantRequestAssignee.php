<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recipient of an Important Request, with their own status and progress. */
class ImportantRequestAssignee extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'completed_at' => 'datetime', 'progress' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ImportantRequest::class, 'important_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
