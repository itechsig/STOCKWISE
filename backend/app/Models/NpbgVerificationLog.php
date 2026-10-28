<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail row. Update/delete are blocked at the model level
 * (not just "no route for it") so a silent edit/removal is impossible even
 * from tinker/a future careless service — the brief is explicit that this
 * trail must never be quietly altered.
 */
class NpbgVerificationLog extends Model
{
    protected $table = 'npbg_verification_logs';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Audit trail NPBG verification tidak boleh diubah.');
        });
        static::deleting(function () {
            throw new \RuntimeException('Audit trail NPBG verification tidak boleh dihapus.');
        });
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(NpbgVerification::class, 'npbg_verification_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
