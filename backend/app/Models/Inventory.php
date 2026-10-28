<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'item_id', 'warehouse_id', 'actual_qty', 'reserved_qty',
        'stock_known', 'last_counted_at', 'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'actual_qty' => 'float',
            'reserved_qty' => 'float',
            'available_qty' => 'float',
            'stock_known' => 'boolean',
            'last_counted_at' => 'datetime',
            'last_movement_at' => 'datetime',
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
}
