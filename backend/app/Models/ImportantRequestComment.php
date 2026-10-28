<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A comment of an Important Request's discussion, or a reply to one (one level deep). */
class ImportantRequestComment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ImportantRequestAttachment::class, 'comment_id');
    }
}
