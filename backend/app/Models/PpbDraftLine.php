<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One item to buy in the Draft PPB list — from a Request Barang (item_request_line_id) or Safety Stock; ppb_id once submitted. */
class PpbDraftLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'submitted_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PpbDraft::class, 'ppb_draft_id');
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(ItemRequestLine::class, 'item_request_line_id');
    }

    public function ppb(): BelongsTo
    {
        return $this->belongsTo(Ppb::class);
    }
}
