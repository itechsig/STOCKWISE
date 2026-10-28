<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One handwritten line of a Kartu Stock: Tgl, No. Bukti, Keterangan, Masuk, Keluar. */
class StockCardEntry extends Model
{
    protected $fillable = ['stock_card_id', 'entry_date', 'doc_no', 'description', 'qty_in', 'qty_out', 'created_by', 'share_link_id'];

    /** so the card's own updated_at reflects its last real activity, not just header edits */
    protected $touches = ['card'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'qty_in' => 'float', 'qty_out' => 'float'];
    }

    protected static function booted(): void
    {
        // $touches only fires on save(); a deleted line is activity too.
        static::deleted(fn (self $entry) => $entry->card?->touch());
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(StockCard::class, 'stock_card_id');
    }
}
