<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['report_date' => 'date', 'completed_at' => 'date'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function subs()
    {
        return $this->hasMany(MaintenanceOrderSub::class);
    }
}
