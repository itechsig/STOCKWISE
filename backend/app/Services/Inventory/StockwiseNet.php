<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * What STOCKWISE itself moved since it started issuing goods (config stockwise.stockwise_ledger_since):
 * + manual RI (RiController::store — not in Accurate), − goods handed out for Request Barang
 * (ItemRequestFulfillmentService: prepared, or what was really handed over). Accurate never sees these,
 * so following Accurate's qty (AccurateDerivedAnalysis) keeps them: stock = Accurate qty + this net.
 */
class StockwiseNet
{
    /** @return array<int, float> item id => net qty */
    public static function byItem(): array
    {
        $since = config('stockwise.stockwise_ledger_since');
        $net = [];
        $in = DB::table('ri')->join('items', 'items.code', '=', 'ri.kode_barang')
            ->whereNull('ri.accurate_apinvoice_id')->whereNotNull('ri.created_by')->where('ri.created_at', '>=', $since)
            ->groupBy('items.id')->selectRaw('items.id, SUM(ri.kuantitas) q')->pluck('q', 'id');
        foreach ($in as $id => $q) {
            $net[(int) $id] = (float) $q;
        }
        // Request Barang: only what was handed over left the stock (a prepared NPBG only holds it)
        $out = DB::table('item_request_fulfillment_lines as fl')
            ->join('item_request_lines as l', 'l.id', '=', 'fl.item_request_line_id')
            ->whereNotNull('l.item_id')->whereNotNull('fl.handed_qty')->where('fl.created_at', '>=', $since)
            ->groupBy('l.item_id')->selectRaw('l.item_id, SUM(fl.handed_qty) q')->pluck('q', 'item_id');
        foreach ($out as $id => $q) {
            $net[(int) $id] = round(($net[(int) $id] ?? 0.0) - (float) $q, 2);
        }
        // a manual NPBG approved by the Direktur (NpbgApprovalService) took its goods out of the stock too
        $approved = DB::table('stock_movements')->where('movement_type', 'STOCK_OUT')->where('reference_type', (new \App\Models\Npbg)->getMorphClass())
            ->where('note', 'like', '%(disetujui)')->where('created_at', '>=', $since)->groupBy('item_id')->selectRaw('item_id, SUM(qty) q')->pluck('q', 'item_id');
        foreach ($approved as $id => $q) {
            $net[(int) $id] = round(($net[(int) $id] ?? 0.0) - (float) $q, 2);
        }

        return $net;
    }
}
