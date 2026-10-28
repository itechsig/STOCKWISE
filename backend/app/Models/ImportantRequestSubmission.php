<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A result sent by a recipient (round 1, then one per revision) and its review. */
class ImportantRequestSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'round' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ImportantRequestAssignee::class, 'assignee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ImportantRequestAttachment::class, 'submission_id');
    }
}
