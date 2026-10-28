<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManufacturingOrderSub extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['finish_date' => 'date', 'row_date' => 'date'];
    }

    public function order()
    {
        return $this->belongsTo(ManufacturingOrder::class, 'manufacturing_order_id');
    }

    public function npbg()
    {
        return $this->belongsTo(Npbg::class, 'npbg_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function ri()
    {
        return $this->belongsTo(Ri::class, 'ri_id');
    }
}
