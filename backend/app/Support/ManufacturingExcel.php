<?php

namespace App\Support;

/**
 * The formulas of the Excel "7. Tracking Manufaktur & Assembly" (sheets
 * "Manufaktur & Assembly" = MA / kind ASSEMBLY and "Manufaktur & Jasa
 * Lain-Lain" = MJ / kind JASA), one unit row at a time.
 */
class ManufacturingExcel
{
    public const REQUESTED = 'REQUESTED';

    public const ON_GOING = 'ON_GOING';

    public const COMPLETED = 'COMPLETED';

    /** Excel blank status — the row misses Hasil Produk / No. Seri / Proses. */
    public const INCOMPLETE = 'INCOMPLETE';

    /**
     * Column "Status Hasil Pengerjaan":
     *   =IF(AND(Foto<>"",TglSelesai<>"",NoRI<>""),"COMPLETED",
     *    IF(NoNPBG<>"","ON-GOING",
     *    IF(AND(HasilProduk<>"",NoSeri<>"",Proses<>""),"REQUESTED","")))
     */
    public static function status(?string $photo, ?string $finishDate, ?string $riNo, ?string $npbgNo,
        ?string $product, ?string $serial, ?string $process): string
    {
        $has = fn (?string $v) => trim((string) $v) !== '';

        if ($has($photo) && $has($finishDate) && $has($riNo)) {
            return self::COMPLETED;
        }
        if ($has($npbgNo)) {
            return self::ON_GOING;
        }

        return $has($product) && $has($serial) && $has($process) ? self::REQUESTED : self::INCOMPLETE;
    }

    /**
     * Column "Helper Nama File" — the name the result photo is saved under:
     *   MA: =CONCAT(HasilProduk," [",NoSeri,"]","_",ItemNo)      e.g. CRADLE CARBON STEEL 3x2 [SIG-58]_1
     *   MJ: =CONCAT(SUBSTITUTE(NoMJ,"/","."),"_",SubMJ,"_",ItemNo) e.g. MJ.25.VIII.021_SUB-01_1
     */
    public static function fileName(string $kind, ?string $number, ?string $subNo, ?string $product, ?string $serial, ?int $itemNo): ?string
    {
        if ($kind === 'JASA') {
            return $number && $subNo ? str_replace('/', '.', $number).'_'.$subNo.'_'.$itemNo : null;
        }

        return $product && $serial ? "{$product} [{$serial}]_{$itemNo}" : null;
    }

    /**
     * Unit serials written in an Accurate NPBG / RI keterangan, in order:
     * "NO SERI: SIG-033, SIG-034" and ranges "SIG-033 s.d SIG-042" / "SIG-58 - SIG-61"
     * (the zero padding of the first number is kept).
     *
     * @return list<string>
     */
    public static function serials(?string $note): array
    {
        $note = mb_strtoupper((string) $note);
        $out = [];
        $range = '/\b(SIG)-(\d{1,4})\s*(?:S\.?\s*D\.?|S\/D|SD|SAMPAI|-|–)\s*(?:\1-)?(\d{1,4})\b/u';
        $note = preg_replace_callback($range, function ($m) use (&$out) {
            [$from, $to] = [(int) $m[2], (int) $m[3]];
            if ($to > $from && $to - $from <= 200) {
                for ($i = $from; $i <= $to; $i++) {
                    $out[] = $m[1].'-'.str_pad((string) $i, strlen($m[2]), '0', STR_PAD_LEFT);
                }

                return ' ';
            }

            return $m[0];
        }, $note);
        preg_match_all('/\b(SIG-\d{1,4})\b/u', $note, $m);

        return array_values(array_unique([...$out, ...$m[1]]));
    }

    /** Header (No. MA / MJ) status rolled up from its unit rows. */
    public static function orderStatus(iterable $rowStatuses): string
    {
        $all = collect($rowStatuses);
        if ($all->isNotEmpty() && $all->every(fn ($s) => $s === self::COMPLETED)) {
            return self::COMPLETED;
        }

        return $all->contains(fn ($s) => in_array($s, [self::ON_GOING, self::COMPLETED], true)) ? self::ON_GOING : self::REQUESTED;
    }
}
