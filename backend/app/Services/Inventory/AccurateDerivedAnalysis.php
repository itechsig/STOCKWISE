<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills the inputs of the STOCKWISE engine (docs/calculation-engine.md) from
 * Accurate data only — there is no Excel source any more (user decision
 * 2026-09-26). Everything else (formula, status, priority) is the existing,
 * unchanged logic:
 *
 *  1. Stock: an OPENING_BALANCE of Accurate's qty for every active item that has
 *     no STOCKWISE inventory yet (same rule as AccurateSyncService's opening
 *     balance; items already tracked by STOCKWISE are never touched).
 *  2. Lead time: median days from PO date to receipt (RI) per item, from real
 *     Accurate purchasing history. No history => no lead time (never guessed).
 *  3. Safety Stock / MIN PR: SafetyStockService formula (2.33 × avg_usage_3m ×
 *     LT/30) on rows marked source_category = ACCURATE. Manually entered rows
 *     are never overwritten.
 *  4. Analysis: InventoryAnalyzer::run() (snapshots the dashboard reads).
 */
class AccurateDerivedAnalysis
{
    public const SOURCE = 'ACCURATE';

    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly SafetyStockService $safetyStocks,
        private readonly InventoryAnalyzer $analyzer,
    ) {}

    /** @return array<string, int|float|string|null> */
    public function run(bool $dryRun = false): array
    {
        $report = [];
        $report += $this->seedOpeningBalances($dryRun);
        $report += $this->followAccurateQty($dryRun);
        $leadTimes = $this->leadTimesFromPurchasing();
        $report['lead_time_items'] = $leadTimes->count();
        $report += $this->refreshSafetyStocks($leadTimes, $dryRun);

        if (! $dryRun) {
            $run = $this->analyzer->run();
            $report['analysis_run_id'] = $run->id;
            $report['analysis_items'] = $run->item_count;
            $report['tidak_aman'] = $run->tidak_aman_count;
            // goods back in stock: requests waiting for them return to Gudang (never an automatic NPBG)
            $report['requests_ready_again'] = app(\App\Services\ItemRequestFulfillmentService::class)->refreshWaiting();
        }

        return $report;
    }

    /** 1. Opening balance from Accurate qty for items without STOCKWISE inventory. */
    private function seedOpeningBalances(bool $dryRun): array
    {
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');
        $items = Item::query()
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory')->whereColumn('inventory.item_id', 'items.id'))
            ->get(['id', 'accurate_qty_onhand']);

        $report = ['opening_balance_items' => $items->count(), 'opening_balance_with_stock' => $items->where('accurate_qty_onhand', '>', 0)->count()];
        if ($dryRun || $items->isEmpty() || $warehouseId === null) {
            $report['opening_balance_skipped_no_warehouse'] = $warehouseId === null ? 1 : 0;

            return $report;
        }

        $batch = (string) Str::uuid();
        foreach ($items->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk, $warehouseId, $batch) {
                foreach ($chunk as $item) {
                    $this->ledger->record([
                        'type' => 'OPENING_BALANCE',
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouseId,
                        'qty' => max((float) $item->accurate_qty_onhand, 0),
                        'note' => 'Saldo awal dari qty Accurate (tanpa Excel)',
                        'batch_uuid' => $batch,
                    ]);
                }
            });
        }
        $report['opening_balance_batch'] = $batch;

        return $report;
    }

    /**
     * 1b. Stock follows Accurate (user decision 2026-09-26: Accurate is the system
     * of record for warehouse transactions): every item's STOCKWISE stock is set to
     * Accurate's qty with an ACCURATE_SYNC movement (audit trail, not a physical
     * count). The difference is applied to the default warehouse, so stock held in
     * other STOCKWISE warehouses still adds up to Accurate's total. Stock reserved
     * by STOCKWISE requests is never released: an item whose Accurate qty is below
     * its reservations is left as-is and counted. Switch off with
     * STOCKWISE_STOCK_FOLLOWS_ACCURATE=false.
     */
    private function followAccurateQty(bool $dryRun): array
    {
        if (! config('stockwise.stock_follows_accurate', true)) {
            return ['accurate_follow' => 'off'];
        }
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');
        if ($warehouseId === null) {
            return ['accurate_follow' => 'no_warehouse'];
        }

        $totals = DB::table('inventory')
            ->selectRaw('item_id, SUM(actual_qty) total_actual')
            ->groupBy('item_id');
        $rows = DB::table('items')
            ->where('items.is_active', true)
            ->leftJoinSub($totals, 't', 't.item_id', '=', 'items.id')
            ->leftJoin('inventory as d', fn ($j) => $j->on('d.item_id', '=', 'items.id')->where('d.warehouse_id', $warehouseId))
            ->get(['items.id', 'items.accurate_qty_onhand', 't.total_actual', 'd.actual_qty as default_actual', 'd.reserved_qty as default_reserved']);

        $changes = [];
        $blocked = 0;
        // what STOCKWISE moved itself (manual RI, Request Barang handed out) stays on top of Accurate's qty
        $net = StockwiseNet::byItem();
        foreach ($rows as $r) {
            $target = max((float) $r->accurate_qty_onhand + ($net[$r->id] ?? 0.0), 0.0);
            $current = (float) ($r->total_actual ?? 0);
            if (abs($target - $current) < 0.005) {
                continue;
            }
            $defaultActual = (float) ($r->default_actual ?? 0);
            $newDefault = max($defaultActual + ($target - $current), 0.0);
            if ($newDefault + 1e-6 < (float) ($r->default_reserved ?? 0)) {
                $blocked++;

                continue;
            }
            $changes[] = ['item_id' => $r->id, 'absolute' => $newDefault];
        }

        $report = ['accurate_follow_changed' => count($changes), 'accurate_follow_blocked_by_reservation' => $blocked];
        if ($dryRun || $changes === []) {
            return $report;
        }

        $batch = (string) Str::uuid();
        foreach (array_chunk($changes, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $warehouseId, $batch) {
                foreach ($chunk as $c) {
                    $this->ledger->record([
                        'type' => 'ACCURATE_SYNC',
                        'item_id' => $c['item_id'],
                        'warehouse_id' => $warehouseId,
                        'absolute' => $c['absolute'],
                        'note' => 'Disamakan dengan qty Accurate (sync)',
                        'batch_uuid' => $batch,
                    ]);
                }
            });
        }
        $report['accurate_follow_batch'] = $batch;

        return $report;
    }

    /**
     * 2. item code => lead time days (median, rounded up) of PO date -> RI date.
     * RI links to its PO line by ID, or else by PO number + item code.
     */
    public function leadTimesFromPurchasing(): Collection
    {
        $byId = DB::table('ri')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'ri.accurate_po_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereNotNull('ri.tgl_ri')->whereNotNull('po.date')->whereNotNull('ri.kode_barang')
            ->select('ri.id', 'ri.kode_barang', 'ri.tgl_ri', 'po.date as po_date');

        $byNumber = DB::table('ri')
            ->join('purchase_orders as po', 'po.number', '=', 'ri.no_po')
            ->whereNull('ri.accurate_po_item_id')
            ->whereNotNull('ri.tgl_ri')->whereNotNull('po.date')->whereNotNull('ri.kode_barang')
            ->select('ri.id', 'ri.kode_barang', 'ri.tgl_ri', 'po.date as po_date');

        return $byId->unionAll($byNumber)->get()
            ->map(fn ($r) => [
                'code' => $r->kode_barang,
                'days' => (int) round((strtotime(substr((string) $r->tgl_ri, 0, 10)) - strtotime(substr((string) $r->po_date, 0, 10))) / 86400),
            ])
            ->filter(fn ($r) => $r['days'] >= 0)
            ->groupBy('code')
            ->map(fn (Collection $g) => (int) ceil($this->median($g->pluck('days')->all())));
    }

    /** 3. Safety Stock rows (source ACCURATE) from NPBG usage + derived lead time. */
    private function refreshSafetyStocks(Collection $leadTimes, bool $dryRun): array
    {
        $usage = $this->safetyStocks->usageAveragesFromNpbg();
        $itemIds = Item::query()->whereIn('code', $usage->keys()->merge(
            ItemSafetyStock::query()->where('source_category', self::SOURCE)->join('items', 'items.id', '=', 'item_safety_stocks.item_id')->pluck('items.code')
        )->unique())->pluck('id', 'code');

        $existing = ItemSafetyStock::query()->whereIn('item_id', $itemIds->values())->get()->groupBy('item_id');
        $asOf = now()->format('d/m/Y');
        $report = ['safety_stock_created' => 0, 'safety_stock_updated' => 0, 'safety_stock_manual_kept' => 0, 'safety_stock_positive' => 0, 'usage_items_without_lead_time' => 0];

        foreach ($itemIds as $code => $itemId) {
            $avg = $usage[$code] ?? ['avg_usage_1m' => 0.0, 'avg_usage_3m' => 0.0, 'avg_usage_6m' => 0.0, 'avg_usage_12m' => 0.0];
            $lt = $leadTimes[$code] ?? null;
            if ($lt === null && $avg['avg_usage_3m'] > 0) {
                $report['usage_items_without_lead_time']++;
            }
            if (SafetyStockService::calculate($avg['avg_usage_3m'], $lt)['safety_stock'] > 0) {
                $report['safety_stock_positive']++;
            }

            $rows = $existing->get($itemId, collect());
            $mine = $rows->firstWhere('source_category', self::SOURCE);
            if ($rows->isNotEmpty() && ! $mine) {
                $report['safety_stock_manual_kept']++;

                continue;
            }

            $data = $avg + [
                'item_id' => $itemId,
                'source_category' => self::SOURCE,
                'period_label' => "Pemakaian NPBG s/d {$asOf}",
                'lead_time_days' => $lt,
                'effective_date' => now()->toDateString(),
                'note' => 'Otomatis: pemakaian dari NPBG Accurate, lead time = median hari PO→RI Accurate'.($lt === null ? ' (tidak ada riwayat pembelian)' : ''),
            ];

            if ($dryRun) {
                $report[$mine ? 'safety_stock_updated' : 'safety_stock_created']++;

                continue;
            }
            if ($mine) {
                $this->safetyStocks->update($mine, $data);
                $report['safety_stock_updated']++;
            } else {
                $this->safetyStocks->create($data);
                $report['safety_stock_created']++;
            }
        }

        return $report;
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
