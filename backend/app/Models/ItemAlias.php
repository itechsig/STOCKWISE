<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemAlias extends Model
{
    protected $fillable = [
        'item_id', 'alias_description', 'alias_normalized', 'source',
        'confidence', 'match_status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'float'];
    }

    protected static function booted(): void
    {
        static::saving(function (ItemAlias $alias) {
            $alias->alias_normalized = Item::normalize($alias->alias_description);
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
