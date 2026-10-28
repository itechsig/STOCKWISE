<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsedReturn extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['return_date' => 'date'];
    }

    public function items()
    {
        return $this->hasMany(UsedReturnItem::class);
    }

    public function npbg()
    {
        return $this->belongsTo(Npbg::class);
    }

    public function ri()
    {
        return $this->belongsTo(Receiving::class, 'ri_id');
    }
}
