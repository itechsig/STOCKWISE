<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * NPBG — mirror of Accurate ARINV (header) + ARINVDET (line), one row per line.
 * Mostly populated by Sync Accurate (App\Services\Accurate\AccurateSyncService),
 * but a row can also be created manually in Stockwise (NpbgController::store()) —
 * those rows have accurate_arinvoice_id/accurate_seq = null and created_by set.
 * Only the Stockwise-owned fields below are editable via update() on an
 * Accurate-synced row; a manually-created row is fully editable through store().
 */
class Npbg extends Model
{
    protected $table = 'npbg';

    protected $guarded = ['id'];

    /**
     * Fields with no Accurate source — the only ones the API allows updating.
     * klasifikasi (Ship Via) and no_seri (SNLine) come from Accurate since 2026-10-05,
     * tipe_npbg (Terms: Penjualan / Non Penjualan) since 2026-10-21 — on a manual NPBG
     * it stays editable (NpbgController::update).
     */
    public const STOCKWISE_OWNED_FIELDS = [
        'deskripsi', 'nama_proyek', 'dikeluarkan_oleh',
    ];

    protected function casts(): array
    {
        return [
            'tgl_npbg' => 'date',
            'shipdate' => 'date',
            'taxdate' => 'date',
            'kuantitas' => 'float',
            'accurate_synced_at' => 'datetime',
        ];
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(NpbgVerification::class);
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(NpbgVerification::class)->latestOfMany();
    }
}
