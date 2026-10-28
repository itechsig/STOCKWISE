<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Kartu Stock of this project (one per item). */
    public function stockCards()
    {
        return $this->hasMany(StockCard::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
