<?php

namespace App\Services\Tracking\Import;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Cell reading shared by the tracking Excel importers — values exactly as typed. */
trait ReadsExcelCells
{
    protected function text(mixed $v): ?string
    {
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if ($v === null) {
            return null;
        }
        $v = trim(str_replace("\u{00A0}", ' ', (string) $v));

        return $v === '' ? null : $v;
    }

    /** Document number (NA/25/VII/251, RI/NV/…) without spaces; "-" is kept (the Excel counts it as filled). */
    protected function docNo(mixed $v): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', (string) $this->text($v)));

        return $v === '' ? null : $v;
    }

    /**
     * A date cell. Typed as text it is Indonesian day/month/year; a mistyped
     * 3-digit year ("01/10/025", "21/11/205") takes the year of the row's document
     * number (NA/25/X/002 → 2025) when there is one.
     */
    protected function date(mixed $v, ?string $docNo = null): ?string
    {
        if ($v === null || $v === '' || $v === '-') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        // a date typed as text is Indonesian day/month/year ("01/10/025" = 1 Oct 2025)
        if (preg_match('#^\s*(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})\s*$#', (string) $v, $m)) {
            $year = match (strlen($m[3])) {
                4 => (int) $m[3],
                3 => 2000 + (int) ($docNo && preg_match('#/(\d{2})/[IVX]+/#', $docNo, $d) ? $d[1] : substr($m[3], -2)),
                default => 2000 + (int) $m[3],
            };
            return checkdate((int) $m[2], (int) $m[1], $year) ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]) : null;
        }
        try {
            return Carbon::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Keterangan + a word typed in a date column instead of a date ("LOST", "NGUTANG"), so it is not lost. */
    protected function withDateWord(?string $note, mixed $dateCell, string $label): ?string
    {
        $word = is_numeric($dateCell) || $this->date($dateCell) ? null : $this->text($dateCell);
        if (! $word || $word === '-') {
            return $note;
        }

        return trim("{$label}: {$word}".($note ? "\n{$note}" : ''));
    }

    protected function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    /** The cached result of a formula cell ("COMPLETED", "ON-GOING" …), upper-cased. */
    protected function cached(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        return strtoupper(trim((string) $cell->getOldCalculatedValue()));
    }

    /** New numbers (MK/…, MA/…) continue after the imported ones (document_sequences per month). */
    protected function bumpSequence(string $number): void
    {
        if (! preg_match('#^([A-Z]{2})/(\d{2})/([IVX]+)/(\d{3})$#', $number, $m)) {
            return;
        }
        $month = array_search($m[3], ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'], true);
        if ($month === false) {
            return;
        }
        $key = ['doc_type' => $m[1], 'prefix' => '-', 'year' => (int) $m[2], 'month' => $month + 1];
        $seq = (int) $m[4];
        $current = DB::table('document_sequences')->where($key)->value('last_sequence');
        if ($current === null) {
            DB::table('document_sequences')->insert($key + ['last_sequence' => $seq, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($current < $seq) {
            DB::table('document_sequences')->where($key)->update(['last_sequence' => $seq, 'updated_at' => now()]);
        }
    }

    /**
     * The evidence file for an Excel "Nama File": exact name first; else, for
     * "<document>-<item>" names, the document's own photo ("RI.NV.25.XI.051-1" →
     * "RI.NV.25.XI.051.jpg" — one photo taken for the whole document).
     */
    protected function findEvidence(array $index, ?string $name, ?string $preferFolder = null): ?string
    {
        $key = \App\Services\Tracking\TrackingPhotoService::key($name);
        if ($key === '') {
            return null;
        }
        $paths = $index[$key] ?? (preg_match('/^(.+)-\d+$/', $key, $m) ? ($index[$m[1]] ?? []) : []);

        return $this->pick($paths, $preferFolder);
    }

    /**
     * The Accurate line of a document an Excel row stands for: among the lines
     * that still have quantity left (earlier rows of the sheet use it up), the one
     * whose description is most alike — the Excel often writes it a little
     * differently ("BAN LUAR STEEL - GITI / 7.50-R16 TT" vs "BAN LUAR - GITI / 7.50-R16").
     * $used (line id => qty taken) is carried over the whole sheet.
     *
     * @param  iterable<object{id:int, deskripsi_barang:?string, kuantitas:mixed}>  $lines
     */
    protected function assignLine(iterable $lines, ?string $desc, float $qty, array &$used): ?object
    {
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $s)));
        $d = $norm($desc);
        $best = null;
        $bestScore = -1.0;
        foreach ([true, false] as $needRoom) {
            foreach ($lines as $l) {
                $room = (float) $l->kuantitas - ($used[$l->id] ?? 0);
                if ($needRoom && $room < 1e-6) {
                    continue;
                }
                $ld = $norm($l->deskripsi_barang);
                similar_text($d, $ld, $pct);
                $score = $d !== '' && $ld === $d ? 1000.0 : $pct;
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$l, $score];
                }
            }
            if ($best) {
                break; // a line with room left wins; over-booking only when every line is used up
            }
        }
        if ($best) {
            $used[$best->id] = ($used[$best->id] ?? 0) + max($qty, 0.000001);
        }

        return $best;
    }

    /** First file of $paths — preferring one inside $preferFolder. */
    protected function pick(array $paths, ?string $preferFolder = null): ?string
    {
        if (! $paths) {
            return null;
        }
        if ($preferFolder) {
            usort($paths, fn ($a, $b) => (int) ! str_contains($a, $preferFolder) <=> (int) ! str_contains($b, $preferFolder));
        }

        return $paths[0];
    }
}
