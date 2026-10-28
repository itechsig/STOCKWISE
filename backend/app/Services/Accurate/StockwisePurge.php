<?php

namespace App\Services\Accurate;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Accurate-only mode (config stockwise.accurate_only): transactions happen in Accurate only and
 * STOCKWISE just reads them, so at the end of every Accurate sync whatever was MADE in STOCKWISE is
 * removed and the data is pure Accurate again:
 *   Request Barang (+ its NPBG / serah terima / photos), manual NPBG / PPB / RI, STOCKWISE POs and
 *   "Menunggu PO" entries, Draft PPB, PPB / PO approval logs, purchase returns, manual stock opname,
 *   the stock movements they made (the stock goes back to Accurate's qty), Master Produk made here and
 *   used nowhere else, their notifications and document numbers.
 * Kept: Kartu Stock, the tracking modules (imported from the company's Excel), settings (users, roles,
 * request links, safety stock / lead time, vendors, units, warehouses).
 */
class StockwisePurge
{
    /** tables whose rows go with an item (an item made in STOCKWISE that nothing else uses) */
    private const ITEM_OWNED = ['inventory', 'inventory_snapshots', 'item_aliases', 'item_safety_stocks', 'stock_movements'];

    public static function enabled(): bool
    {
        return (bool) config('stockwise.accurate_only', false);
    }

    /** @return array<string, int> what was removed, per kind */
    public function run(bool $dryRun = false): array
    {
        $ids = fn (string $table, string $accurateCol) => Schema::hasTable($table) ? DB::table($table)->whereNull($accurateCol)->pluck('id')->all() : [];
        $npbg = $ids('npbg', 'accurate_arinvoice_id');
        $ri = $ids('ri', 'accurate_apinvoice_id');
        $ppb = $ids('ppb', 'accurate_reqid');
        $po = $ids('purchase_orders', 'accurate_po_id');
        $opname = Schema::hasColumn('stock_opnames', 'accurate_itemadj_id') ? $ids('stock_opnames', 'accurate_itemadj_id') : [];
        $requests = DB::table('item_requests')->pluck('id')->all();
        $fulfillments = DB::table('item_request_fulfillments')->pluck('id')->all();
        $returns = Schema::hasTable('purchase_returns') ? DB::table('purchase_returns')->pluck('id')->all() : [];

        // stock movements made by those documents (and the serah terima returns / new-item opening of 0)
        $movements = DB::table('stock_movements')->where(function ($q) use ($npbg, $ri) {
            $q->where(fn ($x) => $x->where('reference_type', (new Npbg)->getMorphClass())->whereIn('reference_id', $npbg ?: [0]))
                ->orWhere(fn ($x) => $x->where('reference_type', (new Ri)->getMorphClass())->whereIn('reference_id', $ri ?: [0]))
                ->orWhere('note', 'like', 'Tidak jadi diserahkan%')
                ->orWhere(fn ($x) => $x->where('movement_type', 'OPENING_BALANCE')->whereNotNull('created_by')->where('qty', 0));
        });
        $movedItems = (clone $movements)->distinct()->pluck('item_id')->all();

        // Master Produk made here (never synced from Accurate) that no kept data uses
        $refTables = collect(Schema::getTableListing(schemaQualified: false))
            ->filter(fn ($t) => ! in_array($t, self::ITEM_OWNED, true) && Schema::hasColumn($t, 'item_id'))->values();
        $purgedRefs = ['item_request_lines', 'ppb_draft_lines', 'purchase_order_items', 'stock_opname_items'];
        $items = DB::table('items')->whereNull('accurate_synced_at')->pluck('id')->filter(function ($id) use ($refTables, $purgedRefs, $po, $opname) {
            foreach ($refTables as $t) {
                $q = DB::table($t)->where('item_id', $id);
                if ($t === 'purchase_order_items') {
                    $q->whereNotIn('purchase_order_id', $po ?: [0]);
                } elseif ($t === 'stock_opname_items') {
                    $q->whereNotIn('stock_opname_id', $opname ?: [0]);
                } elseif (in_array($t, $purgedRefs, true)) {
                    continue;
                }
                if ($q->exists()) {
                    return false;
                }
            }

            return true;
        })->values()->all();

        $report = [
            'request_barang' => count($requests), 'npbg_manual' => count($npbg), 'ppb_manual' => count($ppb), 'ri_manual' => count($ri),
            'po_stockwise' => count($po), 'draft_ppb' => DB::table('ppb_drafts')->count(), 'stock_opname_manual' => count($opname),
            'retur_pembelian' => count($returns), 'mutasi_stok' => (clone $movements)->count(), 'master_produk' => count($items),
        ];
        if ($dryRun || array_sum($report) === 0 && DB::table('ppb_approvals')->doesntExist()) {
            return $report;
        }

        $photos = DB::table('tracking_photos')->where(fn ($q) => $q
            ->where(fn ($x) => $x->whereIn('entity', ['item_request', 'item_request_image'])->whereIn('entity_id', $requests ?: [0]))
            ->orWhere(fn ($x) => $x->where('entity', 'item_request_line_image')
                ->whereIn('entity_id', DB::table('item_request_lines')->whereIn('item_request_id', $requests ?: [0])->select('id')))
            ->orWhere(fn ($x) => $x->where('entity', 'item_request_fulfillment')->whereIn('entity_id', $fulfillments ?: [0]))
            ->orWhere(fn ($x) => $x->where('entity', 'purchase_return')->whereIn('entity_id', $returns ?: [0]))
            // the proof photos / documents of the manual RI
            ->orWhere(fn ($x) => $x->whereIn('entity', ['ri', 'ri_doc'])->whereIn('entity_id', $ri ?: [0])));
        $files = (clone $photos)->pluck('path')->all();

        DB::transaction(function () use ($movements, $photos, $requests, $npbg, $ri, $ppb, $po, $opname, $returns, $items, &$report) {
            $movements->delete();
            $photos->delete();
            DB::table('item_requests')->whereIn('id', $requests ?: [0])->delete(); // lines, approvals, fulfillments go with it
            DB::table('ppb_drafts')->delete();
            DB::table('purchase_orders')->whereIn('id', $po ?: [0])->delete();
            DB::table('npbg')->whereIn('id', $npbg ?: [0])->delete();
            // a "Pengembalian Bekas" RI goes: its tracking row (kept) waits for the RI back again
            $back = DB::table('ri')->whereIn('id', $ri ?: [0])->whereNotNull('used_return_row_id')->get(['used_return_row_id', 'no_ri']);
            foreach ($back as $b) {
                DB::table(\App\Services\Tracking\UsedReturnSheets::OTHER)->where(['id' => $b->used_return_row_id, 'ri_no' => $b->no_ri])
                    ->update(['ri_no' => null, 'return_item_no' => null, 'in_file_name' => null, 'updated_at' => now()]);
                \App\Services\Tracking\UsedReturnSheets::recomputeOther((int) $b->used_return_row_id);
            }
            DB::table('ri')->whereIn('id', $ri ?: [0])->delete();
            DB::table('ppb')->whereIn('id', $ppb ?: [0])->delete();
            DB::table('ppb_approvals')->delete();
            if ($returns) {
                DB::table('purchase_returns')->whereIn('id', $returns)->delete();
            }
            if ($opname) {
                DB::table('stock_opnames')->whereIn('id', $opname)->delete();
            }
            foreach (self::ITEM_OWNED as $t) {
                if ($items && Schema::hasTable($t) && Schema::hasColumn($t, 'item_id')) {
                    DB::table($t)->whereIn('item_id', $items)->delete();
                }
            }
            DB::table('items')->whereIn('id', $items ?: [0])->delete();
            // their notifications (Request Barang / PPB / PO) and document numbers
            $report['notifikasi'] = DB::table('notifications')->where(fn ($q) => $q->where('data', 'like', '%"category": "request"%')->orWhere('data', 'like', '%"category":"request"%')
                ->orWhere('data', 'like', '%"category": "ppb"%')->orWhere('data', 'like', '%"category":"ppb"%')
                ->orWhere('data', 'like', '%"category": "po"%')->orWhere('data', 'like', '%"category":"po"%'))->delete();
            DB::table('document_sequences')->where(fn ($q) => $q->whereIn('doc_type', ['REQ', 'RST'])
                ->orWhere(fn ($x) => $x->whereIn('doc_type', ['NPBG', 'RI', 'PO'])->whereIn('prefix', ['MANUAL', 'PPB'])))->delete();
        });
        foreach ($files as $f) {
            Storage::disk('local')->delete($f);
        }

        // the prepared NPBG of the requests are gone: nothing is held for them any more
        foreach (DB::table('inventory')->where('reserved_qty', '>', 0)->get(['item_id', 'warehouse_id', 'reserved_qty']) as $inv) {
            app(StockLedgerService::class)->record(['type' => 'RELEASE_RESERVATION', 'item_id' => $inv->item_id, 'warehouse_id' => $inv->warehouse_id,
                'qty' => (float) $inv->reserved_qty, 'reserve_delta' => -(float) $inv->reserved_qty, 'note' => 'Pesanan request dilepas (data STOCKWISE dihapus)']);
        }
        $report['stok_disamakan'] = $this->realign($movedItems);

        return $report;
    }

    /** The stock of the items whose STOCKWISE movements went: back to Accurate's qty. */
    private function realign(array $itemIds): int
    {
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');
        if (! $itemIds || ! $warehouseId) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('items')->whereIn('id', $itemIds)->get(['id', 'accurate_qty_onhand']) as $item) {
            $total = (float) DB::table('inventory')->where('item_id', $item->id)->sum('actual_qty');
            $target = max((float) $item->accurate_qty_onhand, 0.0);
            if (abs($total - $target) < 0.005) {
                continue;
            }
            $default = (float) DB::table('inventory')->where(['item_id' => $item->id, 'warehouse_id' => $warehouseId])->value('actual_qty');
            app(StockLedgerService::class)->record(['type' => 'ACCURATE_SYNC', 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
                'absolute' => max($default + ($target - $total), 0.0), 'note' => 'Disamakan dengan qty Accurate (data STOCKWISE dihapus)']);
            $n++;
        }

        return $n;
    }
}
