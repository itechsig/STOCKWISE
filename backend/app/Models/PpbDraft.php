<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A PPB still being prepared (from the Restock button) — App\Services\PpbDraftService. */
class PpbDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tgl_diperlukan' => 'date', 'submitted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PpbDraftLine::class)->orderBy('id');
    }

    public function itemRequest(): BelongsTo
    {
        return $this->belongsTo(ItemRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
