<?php

namespace App\Http\Resources;

use App\Models\Npbg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Npbg */
class NpbgResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_npbg' => $this->no_npbg,
            'tgl_npbg' => $this->tgl_npbg?->toDateString(),
            'shipdate' => $this->shipdate?->toDateString(),
            'taxdate' => $this->taxdate?->toDateString(),
            'tipe_npbg' => $this->tipe_npbg,
            'klasifikasi' => $this->klasifikasi,
            'kode_barang' => $this->kode_barang,
            'deskripsi_barang' => $this->deskripsi_barang,
            'deskripsi' => $this->deskripsi,
            'kuantitas' => $this->kuantitas,
            'satuan' => $this->satuan,
            'peminta' => $this->peminta,
            'divisi' => $this->divisi,
            // Accurate format since Sept 2026 (AccurateLineFields); Klasifikasi = [Ship Via] is 'klasifikasi' above
            'lokasi_gudang' => $this->lokasi_gudang,
            'lokasi_rak' => $this->lokasi_rak,
            'pelanggan' => $this->pelanggan,
            'nama_proyek' => $this->nama_proyek,
            // No. Seri [SNLine] from Accurate
            'no_seri' => $this->no_seri,
            'dikeluarkan_oleh' => $this->dikeluarkan_oleh,
            'keterangan' => $this->keterangan,
            'alamat_kirim' => $this->alamat_kirim,
            'is_manual' => $this->accurate_arinvoice_id === null,
            'accurate_synced_at' => $this->accurate_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // "Kembali Bekas" tick = a linked row in Tracking Pengembalian Bekas (NpbgController::kembaliBekas)
            'kembali_bekas' => $this->kembali_bekas_status !== null,
            'kembali_bekas_status' => $this->kembali_bekas_status,
            'verifications_count' => $this->whenCounted('verifications'),
            'latest_verification_status' => $this->whenLoaded('latestVerification', fn () => $this->latestVerification?->status),
            // manual NPBG: approval Manager → Direktur before the goods leave the stock (detail only)
            'approval' => $this->when($this->approval !== null, fn () => $this->approval),
            'approval_log' => $this->when($this->approval_log !== null, fn () => $this->approval_log),
            'can_decide' => $this->when($this->can_decide !== null, fn () => $this->can_decide),
            'can_resubmit' => $this->when($this->can_resubmit !== null, fn () => $this->can_resubmit),
        ];
    }
}
