<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file of an Important Request (private storage, served after an authorization check). */
class ImportantRequestAttachment extends Model
{
    protected $guarded = ['id'];

    public const KINDS = ['ORIGINAL', 'DISCUSSION', 'SUBMISSION', 'REVISION'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime', 'size' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
