<?php

namespace App\Services\Accurate;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item quantity exactly as Accurate 5 computes it — a line-by-line port of its
 * own stored procedures GETITEMQUANTITY → GETITEMBAL (called with ITEMID null,
 * i.e. "qty now"), over the mirrored ITEMBALANCE / ITEMHIST tables.
 *
 * ITEM.QUANTITY is NOT that number: Accurate does not keep it up to date (on
 * 2026-10-04 it differed for 1,826 of 9,222 products, e.g. OFN.0009 0 vs 66),
 * so the stock of Master Barang must not come from it.
 *
 * GETITEMBAL(ItemNo, TxDate):
 *   dYear / dPeriod = year / month of TxDate
 *   no ITEMBALANCE row for dYear → last earlier year: TxDate = 31-12 of it, dPeriod = 12
 *   SAwal  = OPENINGBAL + AMOUNT1 + … + AMOUNT(dPeriod-1)        (ITEMBALANCE, that year)
 *   STrans = SUM(ITEMHIST.QUANTITY) with TXDATE between 1-dPeriod-dYear and TxDate
 *   QUANTITY = SAwal + STrans                                     (nulls = 0)
 * GETITEMQUANTITY adds the children's balances only when an ITEMID is given — not here.
 */
class AccurateItemQuantity
{
    public static function available(): bool
    {
        return Schema::hasTable('accurate_itembalance') && Schema::hasTable('accurate_itemhist');
    }

    /** @return array<string, float> ITEMNO => quantity on $date (default: today, WIB) */
    public function all(?Carbon $date = null): array
    {
        $date = ($date ?? now('Asia/Jakarta'))->copy()->startOfDay();

        // ITEMBALANCE: ITEMNO => GLYEAR => row
        $balances = [];
        foreach (DB::table('accurate_itembalance')->get() as $b) {
            $balances[trim((string) $b->ITEMNO)][(int) $b->GLYEAR] = $b;
        }

        // ITEMHIST per item per day (small: one row per item per day)
        $hist = [];
        foreach (DB::table('accurate_itemhist')->selectRaw('ITEMNO, TXDATE, SUM(QUANTITY) q')->groupBy('ITEMNO', 'TXDATE')->get() as $h) {
            $hist[trim((string) $h->ITEMNO)][] = [substr((string) $h->TXDATE, 0, 10), (float) $h->q];
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($balances), array_keys($hist))) as $itemNo) {
            $out[$itemNo] = $this->balance($itemNo, $date, $balances[$itemNo] ?? [], $hist[$itemNo] ?? []);
        }

        return $out;
    }

    /**
     * Qty on order exactly as Accurate's GETITEMONORDER(ItemNo, AsOf = today):
     *   SUM((PODET.QUANTITY - PODET.QTYRECV) * PODET.UNITRATIO)
     *   where QUANTITY > QTYRECV and PODET.CLOSED = 0 and PO.PODATE <= AsOf
     * (ITEM.ONORDER is not kept up to date either: 123 products differed on 2026-10-04.)
     *
     * @return array<string, float>|null ITEMNO => qty on order; null when PO / PODET are not staged
     */
    public function onOrder(?Carbon $date = null): ?array
    {
        if (! Schema::hasTable('accurate_podet') || ! Schema::hasTable('accurate_po')) {
            return null;
        }
        $asOf = ($date ?? now('Asia/Jakarta'))->toDateString();

        return DB::table('accurate_podet as d')
            ->join('accurate_po as s', 'd.POID', '=', 's.POID')
            ->whereColumn('d.QUANTITY', '>', 'd.QTYRECV')
            ->where('d.CLOSED', 0)
            ->whereDate('s.PODATE', '<=', $asOf)
            ->selectRaw('d.ITEMNO, SUM((d.QUANTITY - d.QTYRECV) * d.UNITRATIO) q')
            ->groupBy('d.ITEMNO')
            ->pluck('q', 'ITEMNO')
            ->mapWithKeys(fn ($q, $item) => [trim((string) $item) => round((float) $q, 4)])
            ->all();
    }

    /**
     * @param  array<int, object>  $years  GLYEAR => ITEMBALANCE row
     * @param  array<int, array{0:string,1:float}>  $days  [Y-m-d, qty]
     */
    private function balance(string $itemNo, Carbon $txDate, array $years, array $days): float
    {
        $dYear = (int) $txDate->year;
        $dPeriod = (int) $txDate->month;
        $to = $txDate->toDateString();

        if (! isset($years[$dYear])) {
            $earlier = array_filter(array_keys($years), fn ($y) => $y < $dYear);
            if ($earlier !== []) {
                $dYear = max($earlier);
                $dPeriod = 12;
                $to = sprintf('%04d-12-31', $dYear);
            }
        }

        $sAwal = 0.0;
        if (isset($years[$dYear])) {
            $row = $years[$dYear];
            $sAwal = (float) ($row->OPENINGBAL ?? 0);
            for ($m = 1; $m < $dPeriod; $m++) {
                $sAwal += (float) ($row->{'AMOUNT'.$m} ?? 0);
            }
        }

        $from = sprintf('%04d-%02d-01', $dYear, $dPeriod);
        $sTrans = 0.0;
        foreach ($days as [$day, $qty]) {
            if ($day >= $from && $day <= $to) {
                $sTrans += $qty;
            }
        }

        return round($sAwal + $sTrans, 4);
    }
}
