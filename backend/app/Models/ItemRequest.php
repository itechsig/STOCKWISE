<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Request Barang from a karyawan; admin gudang turns its lines into NPBG / PPB (ItemRequestService),
 * an NPBG then goes Manager → Direktur → Lapangan Gudang → serah terima (ItemRequestApprovalService).
 */
class ItemRequest extends Model
{
    public const STATUSES = [
        'MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN', 'APPROVAL_MANAGER', 'APPROVAL_DIREKTUR',
        'DISIAPKAN', 'SIAP_DISERAHKAN', 'DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG',
        'SELESAI', 'DITOLAK', 'DIBATALKAN',
    ];

    /** Past the approvals: Gudang fulfils (ItemRequestFulfillmentService::recompute decides which). */
    public const FULFILMENT_STATUSES = ['DISIAPKAN', 'SIAP_DISERAHKAN', 'DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needed_date' => 'date', 'processed_at' => 'datetime', 'handed_over_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemRequestLine::class)->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ItemRequestApproval::class)->orderBy('id');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(ItemRequestFulfillment::class)->orderBy('seq');
    }

    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }
}
