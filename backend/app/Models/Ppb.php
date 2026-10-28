<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PPB — mirror of Accurate REQUISITION (header) + REQUISITIONDET (line), one row
 * per line. Mostly populated by Sync Accurate (App\Services\Accurate\AccurateSyncService),
 * but a row can also be created manually (PpbController::store()) — those rows have
 * accurate_reqid/accurate_seq = null and created_by set, same convention as Npbg.
 */
class Ppb extends Model
{
    protected $table = 'ppb';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_ppb' => 'date',
            'tgl_diperlukan' => 'date',
            'kuantitas' => 'float',
            'qty_dipesan' => 'float',
            'qty_diterima' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    /** PO lines raised from this PPB line — Accurate's own PODET.REQID/REQSEQ chain. */
    public function poItems()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'accurate_ppb_id');
    }

    /** PO lines of a STOCKWISE PPB: made from it once approved ("Menunggu PO", PpbApprovalService). */
    public function approvedPoItems()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'approved_ppb_id');
    }
}
