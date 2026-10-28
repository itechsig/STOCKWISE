<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_before' => 'float',
            'qty_after' => 'float',
            'difference' => 'float',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function opnameItem(): BelongsTo
    {
        return $this->belongsTo(StockOpnameItem::class, 'stock_opname_item_id');
    }
}
