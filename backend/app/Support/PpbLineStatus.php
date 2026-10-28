<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * PPB line status from Accurate's REQUISITION / REQUISITIONDET + its POs, plus
 * the "Amend" flag of the "1. PPB - RI" Excel. A line can be both — e.g. Amend
 * + Close (user decision 2026-10-01).
 *
 * Accurate closes a PPB (ISCLOSED = 1) as soon as all of it is on a PO — goods
 * can still be on their way. So "closed" alone never means "not coming": the
 * line's PO lines decide (PODET.REQID/REQSEQ → purchase_order_items.accurate_ppb_id).
 *
 *   still coming = PPB open, or a PO line of it still open with qty left to receive
 *   REQUESTED  nothing received, still coming
 *   SHORTAGE   part received, still coming
 *   COMPLETED  received >= requested
 *   CLOSE      not (fully) received and nothing more coming
 *
 *   is_amend:  NOTE   code listed in the header "PPB AMEND (…)"
 *              CLOSED nothing received and nothing coming (never bought / PO closed empty)
 */
class PpbLineStatus
{
    public const REQUESTED = 'REQUESTED';

    public const SHORTAGE = 'SHORTAGE';

    public const COMPLETED = 'COMPLETED';

    public const CLOSE = 'CLOSE';

    /** Filter value for the flag (not a line_status value). */
    public const AMEND = 'AMEND';

    public const AMEND_NOTE = 'NOTE';

    public const AMEND_CLOSED = 'CLOSED';

    /** PO statuses after which nothing more arrives. */
    private const PO_DONE = ['RECEIVED', 'CLOSED', 'CANCELLED', 'REJECTED'];

    /** Item codes in "PPB AMEND (BSP.0727 , SSP.0609 , SSP.0522)" of the PPB header keterangan. */
    public static function amendedCodes(?string $headerNote): array
    {
        if (! preg_match_all('/AMEND\s*\(([^)]*)\)/iu', (string) $headerNote, $m)) {
            return [];
        }

        return collect($m[1])
            ->flatMap(fn ($list) => preg_split('/[\s,;&]+/', mb_strtoupper($list), -1, PREG_SPLIT_NO_EMPTY))
            ->unique()->values()->all();
    }

    public static function of(?float $requested, ?float $received, bool $closed, bool $openPo = false): string
    {
        $requested = (float) ($requested ?? 0);
        $received = (float) ($received ?? 0);

        if ($requested > 0 && $received >= $requested - 1e-6) {
            return self::COMPLETED;
        }
        $stillComing = ! $closed || $openPo;
        if ($received > 1e-6) {
            return $stillComing ? self::SHORTAGE : self::CLOSE;
        }

        return $stillComing ? self::REQUESTED : self::CLOSE;
    }

    /** Why the line is "Amend" (NOTE / CLOSED), or null. */
    public static function amendSource(?float $received, bool $closed, bool $listedAsAmend, bool $openPo = false): ?string
    {
        if ($listedAsAmend) {
            return self::AMEND_NOTE;
        }

        return $closed && ! $openPo && (float) ($received ?? 0) <= 1e-6 ? self::AMEND_CLOSED : null;
    }

    /** ppb.id of the lines with a PO line still open (qty left to receive). @return array<int, true> */
    public static function openPoPpbIds(): array
    {
        return DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->whereNotNull('i.accurate_ppb_id')
            ->whereNotIn('o.status', self::PO_DONE)
            ->whereRaw('COALESCE(i.qty_received, 0) < i.qty - 0.000001')
            ->distinct()->pluck('i.accurate_ppb_id')->flip()->map(fn () => true)->all();
    }

    /**
     * Status + Amend of every PPB line from its own columns and its POs — run after
     * the PO step of each sync (the PPB step runs before the POs are in).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    public static function refreshAll(): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $openPo = self::openPoPpbIds();
        DB::table('ppb')->orderBy('id')
            ->select('id', 'status', 'kuantitas', 'qty_diterima', 'kode_barang', 'keterangan', 'line_status', 'is_amend', 'amend_source')
            ->chunkById(500, function ($rows) use ($openPo, &$counts) {
                foreach ($rows as $p) {
                    $counts['total']++;
                    $received = $p->qty_diterima !== null ? (float) $p->qty_diterima : null;
                    $closed = strtoupper((string) $p->status) === 'CLOSED';
                    $open = isset($openPo[$p->id]);
                    $listed = $p->kode_barang && in_array(mb_strtoupper($p->kode_barang), self::amendedCodes($p->keterangan), true);
                    $source = self::amendSource($received, $closed, $listed, $open);
                    $status = self::of($p->kuantitas !== null ? (float) $p->kuantitas : null, $received, $closed, $open);
                    if ($status === $p->line_status && (bool) $p->is_amend === ($source !== null) && $source === $p->amend_source) {
                        $counts['skipped']++;

                        continue;
                    }
                    DB::table('ppb')->where('id', $p->id)->update(['line_status' => $status, 'is_amend' => $source !== null, 'amend_source' => $source]);
                    $counts['updated']++;
                }
            });

        return $counts;
    }
}
