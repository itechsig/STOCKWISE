<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'item_id', 'warehouse_id', 'movement_type', 'direction', 'qty',
        'actual_before', 'actual_after', 'reserved_before', 'reserved_after',
        'reference_type', 'reference_id', 'batch_uuid', 'note', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => 'integer',
            'qty' => 'float',
            'actual_before' => 'float', 'actual_after' => 'float',
            'reserved_before' => 'float', 'reserved_after' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
