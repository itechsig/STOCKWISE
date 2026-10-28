<?php

namespace App\Services\Accurate;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accurate can change a document's number (e.g. NPBG ATK/26/IX/046 → ATK/26/X/001
 * when its date moves to October). The sync follows the document by its Accurate
 * ID, so `npbg` / `ri` get the new number — but the tracking modules keep the
 * number as text too ("No NPBG" / "No RI" of the Excel). This carries the new
 * number into those copies:
 *
 *   - rows linked to the renumbered document by FK: always;
 *   - rows with only the text (no FK, or FK empty): only when the old number
 *     no longer belongs to any document (never guess on a swap).
 *
 * Only the old number token is replaced, so "NA/26/IX/046, NA/26/IX/047" keeps
 * its other number. Runs right after the NPBG / RI step — before the tracking
 * derivations, which recognise Excel-recorded documents by number.
 */
class DocumentRenumber
{
    /** entity => [source table, number column, [[table, text column, fk column|null, module label], …]] */
    public const COPIES = [
        'npbg' => ['npbg', 'no_npbg', [
            ['maintenance_order_subs', 'npbg_no', 'npbg_id', 'Maintenance'],
            ['manufacturing_order_subs', 'npbg_no', 'npbg_id', 'Manufaktur & Assembly'],
            ['stpp_transactions', 'out_npbg_no', 'out_npbg_id', 'STPP'],
            ['stpp_maintenance_logs', 'npbg_no', 'npbg_id', 'STPP (log maintenance)'],
            ['tyre_changes', 'out_npbg_no', 'out_npbg_id', 'Ban Luar'],
            ['tyre_bpn_transfers', 'npbg_no', 'npbg_id', 'Ban (BPN)'],
            ['lend_transactions', 'out_doc_no', 'out_npbg_id', 'Lend (Tanda Keluar)'],
            ['borrow_transactions', 'out_doc_no', 'return_npbg_id', 'Borrow (Tanda Keluar)'],
            ['used_return_matrix_rows', 'npbg_no', 'npbg_id', 'Barang Bekas Kembali'],
            ['used_return_other_rows', 'npbg_no', 'npbg_id', 'Barang Bekas Kembali (lain-lain)'],
            ['used_returns', 'npbg_ref_raw', 'npbg_id', 'Barang Bekas Kembali'],
        ]],
        'ri' => ['ri', 'no_ri', [
            ['manufacturing_order_subs', 'ri_no', 'ri_id', 'Manufaktur & Assembly'],
            ['tyre_changes', 'in_ri_no', 'in_ri_id', 'Ban Luar'],
            ['borrow_transactions', 'in_doc_no', 'in_ri_id', 'Borrow (Tanda Terima)'],
            ['stpp_transactions', 'return_ri_no', null, 'STPP'],
            ['tyre_bpn_transfers', 'ri_no', null, 'Ban (BPN)'],
            ['used_return_matrix_rows', 'ri_no', null, 'Barang Bekas Kembali'],
            ['used_return_other_rows', 'ri_no', null, 'Barang Bekas Kembali (lain-lain)'],
            ['lend_transactions', 'return_doc_no', null, 'Lend (Tanda Kembali)'],
        ]],
    ];

    /**
     * @param  array<string, array{new:string, ids:int[]}>  $renames  old number => new number + ids of its lines
     * @param  \Closure(array):void  $log  receives one sync_logs row per changed record
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    public function apply(string $entity, array $renames, \Closure $log): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        [$source, $numberCol, $copies] = self::COPIES[$entity];
        $docLabel = strtoupper($entity);

        foreach ($renames as $old => $r) {
            $old = (string) $old;
            $new = (string) $r['new'];
            if ($old === '' || $new === '' || $old === $new) {
                continue;
            }
            $oldStillUsed = DB::table($source)->where($numberCol, $old)->exists();
            $pattern = '/(?<![A-Za-z0-9\/])'.preg_quote($old, '/').'(?![A-Za-z0-9])/u';

            foreach ($copies as [$table, $col, $fk, $module]) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                    continue;
                }
                $hasFk = $fk && Schema::hasColumn($table, $fk);
                if (! $hasFk && $oldStillUsed) {
                    continue; // text only, and the old number still names another document — can't tell which
                }
                $rows = DB::table($table)->where($col, 'like', '%'.$old.'%') // wide net; the regex below replaces the exact number only
                    ->when($hasFk, fn ($q) => $q->where(function ($q) use ($fk, $r, $oldStillUsed) {
                        $q->whereIn($fk, $r['ids']);
                        if (! $oldStillUsed) {
                            $q->orWhereNull($fk);
                        }
                    }))
                    ->get(['id', $col]);

                foreach ($rows as $row) {
                    $counts['total']++;
                    $text = (string) $row->{$col};
                    $replaced = preg_replace($pattern, $new, $text);
                    if ($replaced === null || $replaced === $text) {
                        $counts['skipped']++;

                        continue;
                    }
                    try {
                        DB::table($table)->where('id', $row->id)->update([$col => $replaced]);
                        $counts['updated']++;
                        $log([
                            'entity' => 'renumber',
                            'source_id' => $table.'#'.$row->id,
                            'action' => 'UPDATE',
                            'status' => 'SUCCESS',
                            'message' => "Nomor {$docLabel} berubah di Accurate ({$old} → {$new}) — ikut diubah di {$module}.",
                            'old_data' => [$col => $text, 'module' => $module],
                            'new_data' => [$col => $replaced, 'module' => $module],
                        ]);
                    } catch (\Throwable $e) {
                        $counts['errors']++;
                        $log(['entity' => 'renumber', 'source_id' => $table.'#'.$row->id, 'action' => 'ERROR', 'status' => 'FAILED',
                            'message' => "Gagal mengubah nomor {$old} → {$new} di {$module}: ".$e->getMessage()]);
                    }
                }
            }
        }

        return $counts;
    }
}
