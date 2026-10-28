<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One realisation of a Request Barang: the quantities Gudang gives from real stock (one NPBG,
 * #1, #2 = NPBG lanjutan …), then its serah terima — ItemRequestFulfillmentService.
 */
class ItemRequestFulfillment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['prepared_at' => 'datetime', 'handed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemRequestFulfillmentLine::class)->orderBy('id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function hander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_by');
    }
}
