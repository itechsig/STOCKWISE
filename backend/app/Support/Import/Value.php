<?php

namespace App\Support\Import;

use DateTimeInterface;

/** Cell-value parsing helpers for the Excel importers. */
final class Value
{
    public static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = is_string($v) ? trim($v) : (string) $v;

        return $s === '' || $s === '-' ? null : $s;
    }

    public static function int(mixed $v): ?int
    {
        $f = self::float($v);

        return $f === null ? null : (int) round($f);
    }

    public static function float(mixed $v): ?float
    {
        if ($v === null || $v instanceof DateTimeInterface) {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('/-?\d+(?:[.,]\d+)?/', $v, $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }

        return null;
    }

    public static function boolYaTidak(mixed $v, bool $default = false): bool
    {
        $s = self::str($v);
        if ($s === null) {
            return $default;
        }

        return in_array(mb_strtolower($s), ['ya', 'yes', 'y', 'true', '1'], true);
    }

    /** "STOK 5 PCS" / "STOK 0 PCS " -> 5.0 ; anything without a number -> null (UNKNOWN, not 0). */
    public static function sisaStok(mixed $v): ?float
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/(-?\d+(?:[.,]\d+)?)/', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }
}
