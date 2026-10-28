<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RI — mirror of Accurate APINV (header) + APITMDET (item lines), one row per
 * line. Mostly populated by Sync Accurate (App\Services\Accurate\AccurateSyncService),
 * but a row can also be created manually (RiController::store()) — those rows
 * have accurate_apinvoice_id/accurate_seq = null and created_by set, same
 * convention as Npbg/Ppb.
 */
class Ri extends Model
{
    protected $table = 'ri';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_ri' => 'date',
            'shipdate' => 'date',
            'kuantitas' => 'float',
            'harga_satuan' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    /** Where the RI comes from: PO, BEKAS (used goods back), MANUAL (anything else), ACCURATE (an Accurate RI without PO). */
    public const SOURCES = ['PO', 'BEKAS', 'MANUAL'];

    /** The source as SQL — rows from before the `sumber` column read it from their PO link. */
    public static function sourceSql(): string
    {
        return "COALESCE(sumber, CASE WHEN accurate_po_item_id IS NOT NULL OR COALESCE(no_po, '') <> '' THEN 'PO'"
            ." WHEN accurate_apinvoice_id IS NULL THEN 'MANUAL' ELSE 'ACCURATE' END)";
    }

    /** The line that keeps the proof photos / documents of its RI (the first line of the same no_ri). */
    public function fileOwnerId(): int
    {
        return $this->no_ri ? (int) (self::where('no_ri', $this->no_ri)->min('id') ?? $this->id) : $this->id;
    }

    public function source(): string
    {
        return $this->sumber ?? ($this->accurate_po_item_id !== null || filled($this->no_po) ? 'PO' : ($this->accurate_apinvoice_id === null ? 'MANUAL' : 'ACCURATE'));
    }

    /** The PO line this RI line received against — Accurate's own APITMDET.POID/POSEQ chain (not every RI line has one: internal stock-take style receipts have no PO). */
    public function poItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'accurate_po_item_id');
    }

    /**
     * Resolved vendor — same `vendors` row PO sync creates/uses for the same
     * Accurate PERSONDATA. Named vendorRecord() (not vendor()) because
     * `vendor` is already a plain string column on this model; a same-named
     * relation method would be permanently shadowed by that column and never
     * fire.
     */
    public function vendorRecord()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
