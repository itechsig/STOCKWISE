<?php

namespace App\Services\Inventory;

use App\Models\InventoryAnalysisRun;
use App\Models\InventorySnapshot;
use App\Models\Item;
use App\Support\LooseSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs the STOCKWISE engine across the whole catalog: computes the dataset-level
 * parameters (lead-time threshold, median deficit), analyses every active item and
 * refreshes `inventory_snapshots` + records an `inventory_analysis_runs` row.
 *
 * docs/calculation-engine.md §3, assumptions A2/A3.
 */
class InventoryAnalyzer
{
    public function __construct(private readonly StockwiseEngine $engine) {}

    /**
     * Pure (no I/O beyond the read query, no persistence) — the whole catalog,
     * freshly analysed against the current item_safety_stocks/inventory data.
     * Used both by the live Analisis Inventory list/export (every request,
     * genuinely real-time — no "Hitung ulang" step needed) and by run() below.
     *
     * @return array{rows: Collection<int, object>, lead_time_threshold: float, median_deficit: float, item_count: int, tidak_aman_count: int}
     */
    public function compute(): array
    {
        $fallback = (int) config('stockwise.engine.lead_time_threshold_fallback', 14);

        $invAgg = DB::table('inventory')
            ->selectRaw('item_id, SUM(actual_qty) a, SUM(reserved_qty) r, MAX(stock_known) k')
            ->groupBy('item_id');

        /** @var Collection<int, object> $rows */
        $rows = Item::query()
            ->where('items.is_active', true)
            ->where('items.product_kind', '!=', 'JASA') // a service holds no stock
            ->leftJoin('item_safety_stocks as ss', function ($join) {
                $join->on('ss.item_id', '=', 'items.id')->where('ss.is_effective', true);
            })
            ->leftJoin('units', 'units.id', '=', 'items.unit_id')
            ->leftJoinSub($invAgg, 'inv', 'inv.item_id', '=', 'items.id')
            ->select([
                'items.id', 'items.code', 'items.description', 'items.accurate_category_induk',
                // Same Lead Time the Safety Stock formula itself used (ss.lead_time_days),
                // when an effective row exists — items.lead_time_days and
                // item_safety_stocks.lead_time_days are separately editable and can
                // drift (confirmed: 202/933 items had different values), which would
                // otherwise make Deficit/Priority Score use a different Lead Time than
                // the Safety Stock they're computed against for the same item.
                DB::raw('COALESCE(ss.lead_time_days, items.lead_time_days) as lead_time_days'),
                'units.code as uom',
                DB::raw('COALESCE(ss.safety_stock, 0) as safety_stock'),
                'ss.min_pr', 'ss.avg_usage_1m', 'ss.avg_usage_3m', 'ss.avg_usage_6m', 'ss.avg_usage_12m',
                DB::raw('COALESCE(inv.a, 0) as actual'),
                DB::raw('COALESCE(inv.r, 0) as reserved'),
                DB::raw('COALESCE(inv.k, 0) as stock_known'),
            ])
            ->get()
            ->map(function ($row) {
                $row->actual = (float) $row->actual;
                $row->reserved = (float) $row->reserved;
                $row->available = $row->actual - $row->reserved;
                $row->stock_known = (bool) $row->stock_known;
                $row->safety_stock = (float) $row->safety_stock;
                $row->lead_time = (int) ($row->lead_time_days ?? 0);

                return $row;
            });

        // Dataset parameter: lead-time threshold (75th percentile of positive lead times).
        $leadTimes = $rows->pluck('lead_time')->filter(fn ($lt) => $lt > 0)->values()->all();
        $threshold = count($leadTimes) >= 4
            ? $this->percentile($leadTimes, 75)
            : (float) $fallback;

        // Preliminary pass: status + deficit (independent of median).
        $deficitsTidakAman = [];
        foreach ($rows as $row) {
            $selisih = $row->available - $row->safety_stock;
            $row->status = ($this->isZero($row->available) && $this->isZero($row->safety_stock))
                ? 'BEP'
                : ($selisih >= -1e-6 ? 'AMAN' : 'TIDAK_AMAN');
            $row->deficit = max($row->safety_stock - $row->available, 0.0);
            if ($row->status === 'TIDAK_AMAN') {
                $deficitsTidakAman[] = $row->deficit;
            }
        }

        $medianDeficit = $deficitsTidakAman === [] ? 0.0 : $this->median($deficitsTidakAman);

        $analyzed = $rows->map(function ($row) use ($medianDeficit, $threshold) {
            $a = $this->engine->analyze(
                sisaStok: $row->available,
                safetyStock: $row->safety_stock,
                leadTimeDays: $row->lead_time,
                medianDeficitTidakAman: $medianDeficit,
                leadTimeThreshold: $threshold,
                uom: $row->uom,
            );

            return (object) [
                'item_id' => $row->id,
                'item_code' => $row->code,
                'item_description' => $row->description,
                'category_induk' => $row->accurate_category_induk,
                'uom' => $row->uom,
                'actual' => $row->actual,
                'reserved' => $row->reserved,
                'available' => $a->sisaStok,
                'stock_known' => $row->stock_known,
                'safety_stock' => $a->safetyStock,
                'min_pr' => $row->min_pr !== null ? (float) $row->min_pr : null,
                'avg_usage_1m' => $row->avg_usage_1m !== null ? (float) $row->avg_usage_1m : null,
                'avg_usage_3m' => $row->avg_usage_3m !== null ? (float) $row->avg_usage_3m : null,
                'avg_usage_6m' => $row->avg_usage_6m !== null ? (float) $row->avg_usage_6m : null,
                'avg_usage_12m' => $row->avg_usage_12m !== null ? (float) $row->avg_usage_12m : null,
                'lead_time_days' => $row->lead_time ?: null,
                'selisih' => $a->selisih,
                'status' => $a->status,
                'deficit' => $a->deficit,
                'priority_score' => round($a->priorityScore, 2),
                'priority_level' => $a->priorityLevel,
                'recommendation' => $a->recommendation,
                'recommended_qty' => $a->recommendedQty,
            ];
        });

        return [
            'rows' => $analyzed,
            'lead_time_threshold' => round($threshold, 2),
            'median_deficit' => round($medianDeficit, 2),
            'item_count' => $rows->count(),
            'tidak_aman_count' => count($deficitsTidakAman),
        ];
    }

    /**
     * compute(), filtered/sorted per the query params the Analisis Inventory list
     * and its export/print both accept — kept in one place (InventoryController::analysis()
     * and ExportController's inventory_analysis dataset both call this) so the two
     * always agree exactly.
     *
     * @return array{rows: Collection<int, object>, run: array{lead_time_threshold: float, median_deficit: float, item_count: int, tidak_aman_count: int}}
     */
    public function filtered(Request $request): array
    {
        $computed = $this->compute();
        $rows = $computed['rows'];

        if ($status = $request->string('status')->trim()->upper()->value()) {
            $rows = $rows->where('status', $status);
        }
        if ($level = $request->string('priority_level')->trim()->upper()->value()) {
            $rows = $rows->where('priority_level', $level);
        }
        if ($induk = $request->string('category_induk')->trim()->value()) {
            $rows = $rows->where('category_induk', $induk);
        }
        if ($s = $request->string('search')->trim()->value()) {
            $needle = mb_strtolower(LooseSearch::term($s));
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower(LooseSearch::term((string) $r->item_code)), $needle)
                || str_contains(mb_strtolower(LooseSearch::term((string) $r->item_description)), $needle));
        }

        $sort = $request->string('sort', '-priority_score')->value();
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        $rows = in_array($col, ['priority_score', 'deficit', 'selisih', 'available'], true)
            ? $rows->sortBy($col, SORT_REGULAR, $dir === 'desc')->values()
            : $rows->sortBy('item_code')->values();

        return ['rows' => $rows, 'run' => [
            'lead_time_threshold' => $computed['lead_time_threshold'],
            'median_deficit' => $computed['median_deficit'],
            'item_count' => $computed['item_count'],
            'tidak_aman_count' => $computed['tidak_aman_count'],
        ]];
    }

    /**
     * compute() + persists a snapshot (inventory_snapshots/inventory_analysis_runs) —
     * what the Dashboard cards and the post-sync Accurate pipeline read, refreshed
     * automatically after every sync. The interactive Analisis Inventory list/export
     * no longer reads this snapshot — see compute().
     *
     * @param  int|null  $triggeredBy  user id
     */
    public function run(?int $triggeredBy = null): InventoryAnalysisRun
    {
        $computed = $this->compute();

        $run = InventoryAnalysisRun::create([
            'scope' => 'global',
            'lead_time_threshold' => $computed['lead_time_threshold'],
            'median_deficit' => $computed['median_deficit'],
            'item_count' => $computed['item_count'],
            'tidak_aman_count' => $computed['tidak_aman_count'],
            'triggered_by' => $triggeredBy,
            'computed_at' => now(),
        ]);

        $now = now();
        $snapshotRows = $computed['rows']->map(fn ($a) => [
            'item_id' => $a->item_id,
            'warehouse_id' => null,
            'analysis_run_id' => $run->id,
            'actual' => $a->actual,
            'reserved' => $a->reserved,
            'available' => $a->available,
            'stock_known' => $a->stock_known,
            'safety_stock' => $a->safety_stock,
            'lead_time_days' => $a->lead_time_days,
            'selisih' => $a->selisih,
            'status' => $a->status,
            'deficit' => $a->deficit,
            'priority_score' => $a->priority_score,
            'priority_level' => $a->priority_level,
            'recommendation' => $a->recommendation,
            'recommended_qty' => $a->recommended_qty,
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        InventorySnapshot::query()->whereNull('warehouse_id')->delete();
        foreach (array_chunk($snapshotRows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        return $run;
    }

    private function isZero(float $v): bool
    {
        return abs($v) < 1e-6;
    }

    /**
     * Linear-interpolation percentile (numpy 'linear' / Excel PERCENTILE.INC).
     *
     * @param  list<int|float>  $values
     */
    public function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }

        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        $frac = $rank - $low;

        return $values[$low] + $frac * ($values[$high] - $values[$low]);
    }

    /** @param  list<int|float>  $values */
    public function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
