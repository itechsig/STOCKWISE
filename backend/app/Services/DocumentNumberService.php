<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique document numbers: {DOC}/{PREFIX}/{YY}/{ROMAN-MONTH}/{SEQ}
 * e.g. REQ/SDA/26/IX/001. Sequence is per (doc_type, prefix, year, month) and
 * incremented under a row lock.
 */
class DocumentNumberService
{
    private const ROMAN = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];

    /**
     * Short "{DOC}/{yy}/{roman}/{seq}" numbers as the tracking Excel files write
     * them — MK/25/VII/001 (maintenance), MA/… (manufaktur & assembly), MJ/… (jasa).
     */
    public function nextShort(string $docType, ?Carbon $date = null): string
    {
        return preg_replace('#^([^/]+)/-/#', '$1/', $this->next($docType, '-', $date));
    }

    /** The number nextShort() would hand out now, without taking it (Excel's "next number" cell). */
    public function peekShort(string $docType, ?Carbon $date = null): string
    {
        $date ??= now();
        $last = (int) DB::table('document_sequences')
            ->where(['year' => (int) $date->format('y'), 'month' => (int) $date->format('n'), 'doc_type' => $docType, 'prefix' => '-'])
            ->value('last_sequence');

        return sprintf('%s/%02d/%s/%03d', $docType, (int) $date->format('y'), self::ROMAN[(int) $date->format('n')], $last + 1);
    }

    public function next(string $docType, string $prefix, ?Carbon $date = null): string
    {
        $date ??= now();
        $year = (int) $date->format('y');
        $month = (int) $date->format('n');

        $seq = DB::transaction(function () use ($docType, $prefix, $year, $month) {
            $row = DB::table('document_sequences')
                ->where(compact('year', 'month'))
                ->where('doc_type', $docType)
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $next = $row->last_sequence + 1;
                DB::table('document_sequences')->where('id', $row->id)
                    ->update(['last_sequence' => $next, 'updated_at' => now()]);

                return $next;
            }

            DB::table('document_sequences')->insert([
                'doc_type' => $docType, 'prefix' => $prefix, 'year' => $year,
                'month' => $month, 'last_sequence' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return 1;
        });

        return sprintf(
            '%s/%s/%02d/%s/%03d',
            $docType, $prefix, $year, self::ROMAN[$month], $seq
        );
    }
}
