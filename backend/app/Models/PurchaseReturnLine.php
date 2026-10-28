<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One returned item — a copy of its RI line (the RI line itself may change on sync). */
class PurchaseReturnLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'harga_satuan' => 'float', 'ri_qty' => 'float'];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function ri(): BelongsTo
    {
        return $this->belongsTo(Ri::class);
    }
}
