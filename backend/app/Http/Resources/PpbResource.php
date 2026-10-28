<?php

namespace App\Http\Resources;

use App\Models\Ppb;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ppb */
class PpbResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_ppb' => $this->no_ppb,
            'tgl_ppb' => $this->tgl_ppb?->toDateString(),
            'tgl_diperlukan' => $this->tgl_diperlukan?->toDateString(),
            'is_manual' => $this->accurate_reqid === null,
            'status' => $this->status,
            'line_status' => $this->line_status,
            // Excel "Amend" shown next to the status (NOTE = PPB AMEND (…), CLOSED = closed, nothing received)
            'is_amend' => (bool) $this->is_amend,
            'amend_source' => $this->amend_source,
            'divisi' => $this->divisi,
            'kode_barang' => $this->kode_barang,
            'deskripsi_barang' => $this->deskripsi_barang,
            'kuantitas' => $this->kuantitas,
            'satuan' => $this->satuan,
            'qty_dipesan' => $this->qty_dipesan,
            'qty_diterima' => $this->qty_diterima,
            'peminta' => $this->peminta,
            'keterangan' => $this->keterangan,
            'catatan_baris' => $this->catatan_baris,
            'accurate_synced_at' => $this->accurate_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
