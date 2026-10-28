<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManufacturingOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'completed_at' => 'date'];
    }

    public function subs()
    {
        return $this->hasMany(ManufacturingOrderSub::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
