<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Share link of the Request Barang form: whoever holds an active link can send a request (no account, nothing to read). */
class ItemRequestLink extends Model
{
    protected $fillable = ['token', 'label', 'form_type', 'is_active', 'created_by', 'last_used_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function requests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ItemRequest::class, 'link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
