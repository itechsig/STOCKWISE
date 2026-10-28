<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TyreChange extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['change_date' => 'date', 'in_date' => 'date', 'is_opening' => 'boolean'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function outNpbg()
    {
        return $this->belongsTo(Npbg::class, 'out_npbg_id');
    }

    public function inRi()
    {
        return $this->belongsTo(Ri::class, 'in_ri_id');
    }

    /**
     * Excel "Status": =IF(AND(NoNPBG<>"",NoSeriBaru<>""),IF(AND(NoRI<>"",NoSeriLama<>""),"CLEAR","PENDING RI"),"")
     */
    public static function excelStatus(?string $npbgNo, ?string $newSerial, ?string $riNo, ?string $oldSerial): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';
        if (! $has($npbgNo) || ! $has($newSerial)) {
            return 'INCOMPLETE';
        }

        return $has($riNo) && $has($oldSerial) ? 'CLEAR' : 'PENDING_RI';
    }
}
