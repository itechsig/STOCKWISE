<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a Request Barang's approval flow (ItemRequestApprovalService) — append-only. */
class ItemRequestApproval extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class, 'item_request_id');
    }
}
