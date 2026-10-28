<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSafetyStock extends Model
{
    protected $fillable = [
        'item_id', 'source_category', 'period_label',
        'avg_usage_1m', 'avg_usage_3m', 'avg_usage_6m', 'avg_usage_12m',
        'lead_time_days', 'sqrt_lt', 'safety_stock', 'min_pr',
        'effective_date', 'is_effective', 'needs_review', 'note',
    ];

    protected function casts(): array
    {
        return [
            'avg_usage_1m' => 'float', 'avg_usage_3m' => 'float',
            'avg_usage_6m' => 'float', 'avg_usage_12m' => 'float',
            'sqrt_lt' => 'float', 'safety_stock' => 'float', 'min_pr' => 'float',
            'lead_time_days' => 'integer',
            'effective_date' => 'date',
            'is_effective' => 'boolean', 'needs_review' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
