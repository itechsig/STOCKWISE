<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivingItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_expected' => 'float', 'qty_received' => 'float', 'qty_accepted' => 'float', 'qty_rejected' => 'float', 'into_stock' => 'boolean'];
    }

    public function receiving()
    {
        return $this->belongsTo(Receiving::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function poItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
