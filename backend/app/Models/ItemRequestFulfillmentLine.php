<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemRequestFulfillmentLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'handed_qty' => 'float'];
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(ItemRequestFulfillment::class, 'item_request_fulfillment_id');
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(ItemRequestLine::class, 'item_request_line_id');
    }

    public function npbg(): BelongsTo
    {
        return $this->belongsTo(Npbg::class);
    }
}
