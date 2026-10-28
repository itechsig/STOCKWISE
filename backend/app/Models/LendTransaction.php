<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LendTransaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['out_date' => 'date', 'due_date' => 'date', 'return_date' => 'date', 'qty' => 'float', 'qty_returned' => 'float'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function outNpbg()
    {
        return $this->belongsTo(Npbg::class, 'out_npbg_id');
    }

    public function returnRi()
    {
        return $this->belongsTo(Receiving::class, 'return_ri_id');
    }
}
