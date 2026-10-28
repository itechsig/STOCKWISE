<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceOrderSub extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['finish_date' => 'date', 'report_date' => 'date'];
    }

    public function order()
    {
        return $this->belongsTo(MaintenanceOrder::class, 'maintenance_order_id');
    }

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function npbg()
    {
        return $this->belongsTo(Npbg::class, 'npbg_id');
    }

    public function ri()
    {
        return $this->belongsTo(Ri::class, 'ri_id');
    }
}
