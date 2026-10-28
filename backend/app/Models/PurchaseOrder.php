<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'expected_date' => 'date', 'approved_at' => 'datetime',
            'subtotal' => 'float', 'tax' => 'float', 'total' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
