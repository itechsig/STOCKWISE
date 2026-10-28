<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Retur Pembelian: goods of an RI sent back to the vendor (App\Services\PurchaseReturnService). */
class PurchaseReturn extends Model
{
    public const STATUSES = ['DIBUAT', 'DIKIRIM', 'SELESAI', 'DIBATALKAN'];

    public const RESOLUTIONS = ['GANTI_BARANG', 'POTONG_TAGIHAN'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tgl_retur' => 'date', 'sent_at' => 'datetime', 'resolved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
