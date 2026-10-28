<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StppTransaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['out_date' => 'date', 'return_date' => 'date'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function serialUnit()
    {
        return $this->belongsTo(SerialUnit::class);
    }

    public function outNpbg()
    {
        return $this->belongsTo(Npbg::class, 'out_npbg_id');
    }

    public function returnRi()
    {
        return $this->belongsTo(Receiving::class, 'return_ri_id');
    }

    /** Excel "Status": PASSIVE = Tanda Kembali + Bukti Terima; ACTIVE = No. NPBG + Bukti Keluar. */
    public static function excelStatus(?string $npbgNo, bool $hasOut, ?string $riNo, bool $hasIn): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';
        if ($has($riNo) && $hasIn) {
            return 'PASSIVE';
        }

        return $has($npbgNo) && $hasOut ? 'ACTIVE' : 'INCOMPLETE';
    }
}
