<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Klarifikasi/Verifikasi Barang for one npbg row — see the migration docblock for the status flow. */
class NpbgVerification extends Model
{
    protected $table = 'npbg_verifications';

    protected $guarded = ['id'];

    public const STATUS_DIAJUKAN = 'DIAJUKAN';

    public const STATUS_DIPROSES = 'DIPROSES';

    public const STATUS_ALTERNATIF_DITAWARKAN = 'ALTERNATIF_DITAWARKAN';

    public const STATUS_MENUNGGU_RESPON = 'MENUNGGU_RESPON';

    public const STATUS_PERLU_VERIFIKASI_BOS = 'PERLU_VERIFIKASI_BOS';

    public const STATUS_DISETUJUI = 'DISETUJUI';

    public const STATUS_DITOLAK = 'DITOLAK';

    public const STATUS_SELESAI = 'SELESAI';

    /** Statuses where the case is still open (a new one can't be opened while one of these is active). */
    public const OPEN_STATUSES = [
        self::STATUS_DIAJUKAN, self::STATUS_DIPROSES, self::STATUS_ALTERNATIF_DITAWARKAN,
        self::STATUS_MENUNGGU_RESPON, self::STATUS_PERLU_VERIFIKASI_BOS,
    ];

    protected function casts(): array
    {
        return [
            'escalated_at' => 'datetime',
            'responded_at' => 'datetime',
            'decided_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function npbg(): BelongsTo
    {
        return $this->belongsTo(Npbg::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NpbgVerificationLog::class)->orderBy('created_at');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
