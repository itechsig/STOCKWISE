<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_price' => 'float', 'line_total' => 'float', 'qty_received' => 'float'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** The vendor of this line when it differs per item (one PO number, several vendors); null = the PO's vendor. */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /** The vendor this line is bought from: its own, else the PO's. */
    public function vendorName(): ?string
    {
        return $this->vendor?->name ?? $this->purchaseOrder?->vendor?->name;
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** The Accurate PPB (REQUISITIONDET) line this PO line was raised from — chain PPB->PO from Accurate's own PODET.REQID/REQSEQ. */
    public function accuratePpb()
    {
        return $this->belongsTo(Ppb::class, 'accurate_ppb_id');
    }

    /** The approved PPB line a "Menunggu PO" line was made from (PpbApprovalService) — kept apart from Accurate's real PO chain. */
    public function approvedPpb()
    {
        return $this->belongsTo(Ppb::class, 'approved_ppb_id');
    }

    /** Draft PO: each vendor's price for this line (the comparison) */
    public function quotes()
    {
        return $this->hasMany(PurchaseOrderQuote::class);
    }

    /** RI (APITMDET) lines that fulfilled this PO line — Accurate's own APITMDET.POID/POSEQ chain. */
    public function riLines()
    {
        return $this->hasMany(Ri::class, 'accurate_po_item_id');
    }
}
