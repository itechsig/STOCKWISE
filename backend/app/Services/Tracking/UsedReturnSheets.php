<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\DB;

/**
 * The two tracking sheets of the Excel "8. Tracking Pengembalian Bekas" and their formulas.
 *   Spare Part:      =IF(NoRI<>"","CLEAR",IF(NoNPBG<>"","PENDING",""))
 *   Spare Part Lain: =IF(AND(NoRI<>"",FotoTerima<>""),"CLEAR",IF(NoNPBG<>"","PENDING",""))
 */
class UsedReturnSheets
{
    public const MATRIX = 'used_return_matrix_rows';

    public const OTHER = 'used_return_other_rows';

    /** Photo entity of "Spare Part Lain" rows (slots out = Foto Keluar, in = Foto Terima). */
    public const PHOTO_ENTITY = 'used_return_line';

    /** Component columns of sheet "Spare Part", in Excel order (code => header). */
    public const COMPONENTS = [
        'BONIT_BR' => 'Bonit BR', 'PEN_BR' => 'Pen BR', 'PEN_SS' => 'Pen SS', 'VALVE_BR' => 'Valve BR', 'CYL_CAP' => 'Cyl Cap',
        'MUR_BR' => 'Mur Br', 'MUR_CS' => 'Mur CS', 'MUR_GI' => 'Mur GI', 'MUR_SS' => 'Mur SS', 'PER_CS' => 'Per CS',
        'BAUT_BR' => 'Baut BR', 'BAUT_CS' => 'Baut CS', 'BAUT_GI' => 'Baut GI', 'BAUT_SS' => 'Baut SS',
    ];

    public static function matrixStatus(?string $npbgNo, ?string $riNo): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';

        return $has($riNo) ? 'CLEAR' : ($has($npbgNo) ? 'PENDING' : 'INCOMPLETE');
    }

    public static function otherStatus(?string $npbgNo, ?string $riNo, bool $hasPhotoIn): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';

        return $has($riNo) && $hasPhotoIn ? 'CLEAR' : ($has($npbgNo) ? 'PENDING' : 'INCOMPLETE');
    }

    /** "Helper Nama File Foto Keluar / Terima": ATK.25.IX.017_11 / RI.NV.25.IX.100_11 */
    public static function fileName(?string $doc, $itemNo): ?string
    {
        return $doc && $itemNo !== null && $itemNo !== '' ? str_replace('/', '.', $doc).'_'.$itemNo : null;
    }

    public static function recomputeOther(int $id): void
    {
        $r = DB::table(self::OTHER)->find($id);
        if (! $r) {
            return;
        }
        $hasIn = trim((string) $r->in_file_name) !== '' || app(TrackingPhotoService::class)->has(self::PHOTO_ENTITY, $id, 'in');
        DB::table(self::OTHER)->where('id', $id)->update(['status' => self::otherStatus($r->npbg_no, $r->ri_no, $hasIn)]);
    }
}
