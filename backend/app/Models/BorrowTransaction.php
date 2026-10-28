<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowTransaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['borrowed_at' => 'date', 'returned_at' => 'date', 'qty' => 'float', 'qty_returned' => 'float'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function lenderVendor()
    {
        return $this->belongsTo(Vendor::class, 'lender_vendor_id');
    }

    public function returnNpbg()
    {
        return $this->belongsTo(Npbg::class, 'return_npbg_id');
    }

    public function inRi()
    {
        return $this->belongsTo(Ri::class, 'in_ri_id');
    }
}
