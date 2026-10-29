<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemRequestLine extends Model
{
    public const DECISIONS = ['NPBG', 'PPB', 'TOLAK'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'approved_qty' => 'float', 'fulfilled_qty' => 'float', 'decided_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** What is still owed: approved − handed over (0 once closed or nothing approved). */
    public function remaining(): float
    {
        if ($this->closed_at !== null || ! in_array($this->decision, ['NPBG', 'PPB'], true) || $this->approved_qty === null) {
            return 0.0;
        }

        return max(0.0, round($this->approved_qty - $this->fulfilled_qty, 2));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function npbg(): BelongsTo
    {
        return $this->belongsTo(Npbg::class);
    }

    public function ppb(): BelongsTo
    {
        return $this->belongsTo(Ppb::class);
    }

    /** the Draft PPB a PPB line waits in until it is submitted */
    public function ppbDraft(): BelongsTo
    {
        return $this->belongsTo(PpbDraft::class);
    }

    /**
     * The line becomes the Master Produk item admin gudang matched: its name, code and unit from the
     * database from then on (approval, NPBG, PPB, serah terima, history); what the requester typed stays
     * in deskripsi_peminta.
     */
    public function matchTo(Item $item): self
    {
        return $this->fill([
            'item_id' => $item->id, 'kode_barang' => $item->code,
            'deskripsi_peminta' => $this->deskripsi_peminta ?? $this->deskripsi_barang,
            // Keep the original requester's description - don't change it to database name
            'deskripsi_barang' => $this->deskripsi_peminta ?? $this->deskripsi_barang,
            'satuan' => $item->unit?->code ?: $this->satuan,
        ]);
    }

    /** What the requester typed (before the match). */
    public function requestedText(): string
    {
        return (string) ($this->deskripsi_peminta ?? $this->deskripsi_barang);
    }
}
