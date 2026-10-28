<?php

use App\Models\Npbg;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Request Barang: the stock leaves with the serah terima, a prepared NPBG only holds it. The NPBG prepared
 * before this (stock taken out at once) and not handed over yet: their goods go back into the stock and
 * are held for them instead — so the serah terima takes them out once, not twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ledger = app(StockLedgerService::class);
        $morph = (new Npbg)->getMorphClass();
        $lines = DB::table('item_request_fulfillment_lines as fl')
            ->join('item_request_fulfillments as f', 'f.id', '=', 'fl.item_request_fulfillment_id')
            ->whereIn('f.status', ['DISIAPKAN', 'SIAP'])->whereNotNull('fl.npbg_id')
            ->get(['fl.npbg_id', 'fl.qty']);
        foreach ($lines as $l) {
            $done = DB::table('stock_movements')->where(['reference_type' => $morph, 'reference_id' => $l->npbg_id])->where('movement_type', 'RESERVATION')->exists();
            if ($done) {
                continue;
            }
            $left = (float) $l->qty;
            $outs = DB::table('stock_movements')->where(['reference_type' => $morph, 'reference_id' => $l->npbg_id, 'movement_type' => 'STOCK_OUT'])
                ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, SUM(qty) q')->get();
            $npbg = (new Npbg)->forceFill(['id' => $l->npbg_id]);
            foreach ($outs as $o) {
                $q = round(min($left, (float) $o->q), 2);
                if ($q <= 0) {
                    continue;
                }
                $no = DB::table('npbg')->where('id', $l->npbg_id)->value('no_npbg');
                $ledger->record(['type' => 'RETURN', 'item_id' => $o->item_id, 'warehouse_id' => $o->warehouse_id, 'qty' => $q, 'reference' => $npbg,
                    'note' => "Belum diserahkan · NPBG {$no}: stok keluar saat serah terima"]);
                $ledger->record(['type' => 'RESERVATION', 'item_id' => $o->item_id, 'warehouse_id' => $o->warehouse_id, 'qty' => $q, 'reserve_delta' => $q,
                    'reference' => $npbg, 'note' => "Dipesan NPBG {$no} sampai serah terima"]);
                $left = round($left - $q, 2);
            }
        }
    }

    public function down(): void
    {
        // the holds stay (the serah terima releases them)
    }
};
