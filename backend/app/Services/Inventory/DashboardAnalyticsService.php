<?php

namespace App\Services\Inventory;

use App\Models\InventoryAnalysisRun;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Dashboard — one filtered read of `inventory_snapshots` (via the
 * same Item::filtered() used by Master Barang / Inventory Analysis) feeds
 * every KPI, chart, note, and the priority list below. Nothing here
 * recomputes Status/Selisih/Defisit/Priority Score itself — those are
 * read verbatim from the snapshot (StockwiseEngine's own output), never
 * re-derived, so this dashboard can never disagree with the Items or
 * Inventory Analysis pages for the same filters (brief §13).
 *
 * Pipeline (brief §13 — kept as separate steps, not fused):
 *   filteredRows()   — Filtering (single query)
 *   kpis()/health…() — KPI Calculation
 *   *Chart()/notes() — Chart Data / Insight
 */
class DashboardAnalyticsService
{
    /** Z_FACTOR-style constants live in SafetyStockService; nothing recomputed here. */
    public function build(Request $request): array
    {
        $rows = $this->filteredRows($request);
        $codes = $rows->pluck('code')->filter()->values();

        $kpis = $this->kpis($rows);
        $run = InventoryAnalysisRun::query()->latest('computed_at')->first();
        $leadTimeThreshold = $request->filled('high_lead_time_threshold')
            ? (float) $request->input('high_lead_time_threshold')
            : (float) ($run->lead_time_threshold ?? 14);

        return [
            'generated_at' => now()->toIso8601String(),
            'run' => $run ? [
                'lead_time_threshold' => (float) $run->lead_time_threshold,
                'median_deficit' => (float) $run->median_deficit,
                'item_count' => $run->item_count,
                'computed_at' => $run->computed_at?->toIso8601String(),
            ] : null,
            'kpis' => $kpis,
            'health_score' => $this->healthScore($kpis),
            'charts' => [
                'status_distribution' => $this->statusDistribution($rows),
                'top_deficit' => $this->topDeficit($rows),
                'stock_vs_safety' => $this->stockVsSafety($rows),
                'per_warehouse' => $this->perGroupStatus($rows, 'warehouse_label', 'Tanpa Gudang'),
                'per_category' => $this->perGroupStatus($rows, 'category_label', 'Tanpa Kategori'),
                'stock_vs_safety_per_warehouse' => $this->stockVsSafetyPerGroup($rows, 'warehouse_label', 'Tanpa Gudang'),
                'ppb_status' => $this->ppbStatus($codes),
                'ppb_per_divisi' => $this->ppbPerDivisi($codes),
                'npbg_per_month' => $this->npbgPerMonth($codes),
                'npbg_top_usage' => $this->npbgTopUsage($codes),
                'npbg_top_divisi' => $this->npbgTopDivisi($codes),
            ],
            'notes' => $this->notes($rows, $kpis, $leadTimeThreshold),
            'priority_items' => $this->priorityItems($rows),
        ];
    }

    /**
     * The one filtered query everything else reads from. Same Item::filtered()
     * as ItemController/ExportController — same filters, same snapshot join,
     * so "apply this filter" means the same thing everywhere in the app.
     */
    private function filteredRows(Request $request): Collection
    {
        return Item::filtered($request)
            ->where('items.is_active', true)
            ->leftJoin('warehouses', 'warehouses.id', '=', 'items.default_warehouse_id')
            ->addSelect([
                'warehouses.code as warehouse_code',
                DB::raw('COALESCE(warehouses.name, warehouses.code) as warehouse_label'),
                DB::raw('COALESCE(items.accurate_category_induk, "Tanpa Kategori") as category_label'),
                'snap.available as sisa_stok',
                'snap.safety_stock as safety_stock',
                'snap.selisih as selisih',
                'snap.status as status',
                'snap.deficit as deficit',
                'snap.priority_score as priority_score',
                'snap.priority_level as priority_level',
                'snap.lead_time_days as lead_time_days',
            ])
            ->get()
            ->map(function ($row) {
                // Null/blank numeric -> 0 (brief §1/§2), never left null for KPI math.
                $row->sisa_stok = (float) ($row->sisa_stok ?? 0);
                $row->safety_stock = (float) ($row->safety_stock ?? 0);
                $row->selisih = $row->selisih !== null ? (float) $row->selisih : ($row->sisa_stok - $row->safety_stock);
                $row->deficit = $row->deficit !== null ? (float) $row->deficit : max($row->safety_stock - $row->sisa_stok, 0.0);
                $row->priority_score = (float) ($row->priority_score ?? 0);
                $row->lead_time_days = (int) ($row->lead_time_days ?? 0);
                // Status/BEP override — read from the snapshot when present; for
                // an item with no snapshot row at all (shouldn't happen once
                // analyze() has run, but never guess a status if it does),
                // re-derive with the exact same rule as StockwiseEngine::status().
                $row->status = $row->status ?? (
                    (abs($row->sisa_stok) < 1e-6 && abs($row->safety_stock) < 1e-6)
                        ? 'BEP'
                        : ($row->selisih >= -1e-6 ? 'AMAN' : 'TIDAK_AMAN')
                );

                return $row;
            });
    }

    /** @return array<string, int|float> */
    private function kpis(Collection $rows): array
    {
        return [
            'total_barang' => $rows->count(),
            'barang_aman' => $rows->where('status', 'AMAN')->count(),
            'perlu_dibeli' => $rows->where('status', 'TIDAK_AMAN')->count(),
            // "Stok Habis" = BEP (stock AND Safety Stock both zero): out of stock but
            // NOT a shortage — never counted in perlu_dibeli / total_kekurangan (user
            // decision 2026-09-28). A TIDAK_AMAN item with zero stock is "Perlu Dibeli".
            'stok_habis' => $rows->where('status', 'BEP')->count(),
            'barang_bep' => $rows->where('status', 'BEP')->count(),
            'total_stok' => round((float) $rows->sum('sisa_stok'), 2),
            'total_batas_aman' => round((float) $rows->sum('safety_stock'), 2),
            'total_kekurangan' => round((float) $rows->sum('deficit'), 2),
        ];
    }

    /** @return array{value: float, category: string} */
    private function healthScore(array $kpis): array
    {
        $total = $kpis['total_barang'];
        // BEP counts as "aman" for Health Score only (brief §3) — KPI "Barang
        // Aman" above stays AMAN-only, on purpose; these are different numbers.
        $value = $total > 0
            ? round((($kpis['barang_aman'] + $kpis['barang_bep']) / $total) * 100, 1)
            : 0.0;

        $category = match (true) {
            $value >= 80 => 'Sehat',
            $value >= 50 => 'Perlu Perhatian',
            default => 'Kritis',
        };

        return ['value' => $value, 'category' => $category];
    }

    /** @return list<array{name: string, value: int}> */
    private function statusDistribution(Collection $rows): array
    {
        $counts = ['AMAN' => 0, 'TIDAK_AMAN' => 0, 'BEP' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + 1;
        }

        return collect($counts)->map(fn ($v, $k) => ['name' => $k, 'value' => $v])->values()->all();
    }

    /** @return list<array{code: string, description: string, deficit: float}> */
    private function topDeficit(Collection $rows): array
    {
        return $rows->filter(fn ($r) => $r->deficit > 1e-6)
            ->sortByDesc('deficit')
            ->take(10)
            ->map(fn ($r) => ['code' => $r->code, 'description' => $r->description, 'deficit' => round($r->deficit, 2)])
            ->values()->all();
    }

    /**
     * Same top-10-by-deficit set as topDeficit() (not a second, independently
     * ranked list) — one "which items need attention" ranking reused by both
     * charts, so they can never show different items for the same filters.
     */
    private function stockVsSafety(Collection $rows): array
    {
        return $rows->filter(fn ($r) => $r->deficit > 1e-6)
            ->sortByDesc('deficit')
            ->take(10)
            ->map(fn ($r) => [
                'code' => $r->code, 'description' => $r->description,
                'sisa_stok' => round($r->sisa_stok, 2), 'safety_stock' => round($r->safety_stock, 2),
            ])
            ->values()->all();
    }

    /** @return list<array{name: string, AMAN: int, TIDAK_AMAN: int, BEP: int}> */
    private function perGroupStatus(Collection $rows, string $groupField, string $fallbackLabel): array
    {
        return $rows->groupBy(fn ($r) => $r->{$groupField} ?: $fallbackLabel)
            ->map(function (Collection $group, string $name) {
                return [
                    'name' => $name,
                    'AMAN' => $group->where('status', 'AMAN')->count(),
                    'TIDAK_AMAN' => $group->where('status', 'TIDAK_AMAN')->count(),
                    'BEP' => $group->where('status', 'BEP')->count(),
                ];
            })
            ->sortByDesc(fn ($g) => $g['AMAN'] + $g['TIDAK_AMAN'] + $g['BEP'])
            ->values()->all();
    }

    /** @return list<array{name: string, total_stok: float, total_batas_aman: float}> */
    private function stockVsSafetyPerGroup(Collection $rows, string $groupField, string $fallbackLabel): array
    {
        return $rows->groupBy(fn ($r) => $r->{$groupField} ?: $fallbackLabel)
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'total_stok' => round((float) $group->sum('sisa_stok'), 2),
                'total_batas_aman' => round((float) $group->sum('safety_stock'), 2),
            ])
            ->sortByDesc('total_stok')
            ->values()->all();
    }

    /** @param  Collection<int, string>  $codes */
    private function ppbStatus(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        return DB::table('ppb')->whereIn('kode_barang', $codes)
            // per-line purchase status (Excel "1. PPB - RI"): Requested / Shortage / Completed / Close / Amend
            ->selectRaw('line_status, count(*) c')->groupBy('line_status')
            ->get()->map(fn ($r) => ['name' => $r->line_status ?? 'Tidak diketahui', 'value' => $r->c])->all();
    }

    /**
     * PPB.divisi is derived from a segment of no_ppb and is "NA" for ~90% of
     * real rows (confirmed against live data) — not a meaningful per-division
     * breakdown as-is. Shown anyway (brief asks for it explicitly), but
     * "NA" is relabeled so it doesn't read as a real division name.
     */
    private function ppbPerDivisi(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        return DB::table('ppb')->whereIn('kode_barang', $codes)
            ->selectRaw('divisi, count(*) c')->groupBy('divisi')
            ->orderByDesc('c')->limit(10)
            ->get()->map(fn ($r) => ['name' => ($r->divisi === 'NA' || ! $r->divisi) ? 'Tidak diketahui' : $r->divisi, 'value' => $r->c])->all();
    }

    private function npbgPerMonth(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        // DATE_FORMAT is MySQL-only — SQLite (test suite, phpunit.xml) has no
        // such function. strftime is SQLite's equivalent; substr(...,1,7) on
        // an ISO date string works identically on both since 'YYYY-MM-DD'
        // sorts/slices the same way regardless of driver.
        $ym = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', tgl_npbg)"
            : "DATE_FORMAT(tgl_npbg, '%Y-%m')";

        return DB::table('npbg')->whereIn('kode_barang', $codes)->whereNotNull('tgl_npbg')
            ->where('tgl_npbg', '>=', now()->subMonthsNoOverflow(12)->startOfMonth())
            ->selectRaw("{$ym} as ym, SUM(kuantitas) as qty, COUNT(*) as c")
            ->groupBy('ym')->orderBy('ym')
            ->get()->map(fn ($r) => ['name' => $r->ym, 'qty' => (float) $r->qty, 'count' => $r->c])->all();
    }

    /** Grouped by deskripsi_barang (the item text on the NPBG line), not the free-text `keterangan` (too scattered — 2,474 distinct values for 8,587 rows, confirmed). */
    private function npbgTopUsage(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        return DB::table('npbg')->whereIn('kode_barang', $codes)->whereNotNull('deskripsi_barang')
            ->selectRaw('deskripsi_barang, count(*) c, sum(kuantitas) qty')
            ->groupBy('deskripsi_barang')->orderByDesc('c')->limit(10)
            ->get()->map(fn ($r) => ['name' => $r->deskripsi_barang, 'count' => $r->c, 'qty' => (float) $r->qty])->all();
    }

    private function npbgTopDivisi(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        return DB::table('npbg')->whereIn('kode_barang', $codes)->whereNotNull('divisi')
            ->selectRaw('divisi, count(*) c, sum(kuantitas) qty')
            ->groupBy('divisi')->orderByDesc('c')->limit(10)
            ->get()->map(fn ($r) => ['name' => $r->divisi, 'count' => $r->c, 'qty' => (float) $r->qty])->all();
    }

    /**
     * Rule-based only (brief §7 explicit: "bukan AI yang mengarang informasi")
     * — every note is a plain fact read from $rows/$kpis, in the specified
     * priority order.
     *
     * @return list<string>
     */
    private function notes(Collection $rows, array $kpis, float $leadTimeThreshold): array
    {
        // Empty dataset (no items match the active filter) — say so plainly
        // rather than falling through to "semua barang aman", which would be
        // vacuously true but misleading (there are no items at all, not zero
        // problem items).
        if ($kpis['total_barang'] === 0) {
            return ['Tidak ada barang yang cocok dengan filter saat ini.'];
        }

        $notes = [];

        if ($kpis['barang_bep'] > 0) {
            $notes[] = "{$kpis['barang_bep']} barang Stok Habis (BEP: stok dan safety stock nol, tidak ada pemakaian) — tidak termasuk kekurangan dan tidak perlu dibeli.";
        }

        if ($kpis['perlu_dibeli'] === 0) {
            $notes[] = 'Semua barang dalam kondisi aman — tidak ada yang perlu dibeli saat ini.';
        } else {
            $notes[] = "{$kpis['perlu_dibeli']} barang berstatus TIDAK AMAN dan perlu diperhatikan.";
        }

        $topDeficit = $rows->sortByDesc('deficit')->first();
        if ($topDeficit && $topDeficit->deficit > 1e-6) {
            $notes[] = "Kekurangan terbesar: {$topDeficit->description} ({$topDeficit->code}), kurang ".rtrim(rtrim(number_format($topDeficit->deficit, 2), '0'), '.').' unit.';
        }

        $highLeadTimeCount = $rows->filter(fn ($r) => $r->status === 'TIDAK_AMAN' && $r->lead_time_days >= $leadTimeThreshold)->count();
        if ($highLeadTimeCount > 0) {
            $notes[] = "{$highLeadTimeCount} barang TIDAK AMAN punya Lead Time tinggi (≥ {$leadTimeThreshold} hari) — butuh perhatian pengadaan lebih awal.";
        }

        return $notes;
    }

    /**
     * "Yang Perlu Segera Dibeli" — TIDAK_AMAN only, ranked by the same
     * Priority Score StockwiseEngine already computed (brief §9), top 8.
     */
    private function priorityItems(Collection $rows): array
    {
        return $rows->where('status', 'TIDAK_AMAN')
            ->sortByDesc('priority_score')
            ->take(8)
            ->map(fn ($r) => [
                'code' => $r->code, 'description' => $r->description,
                'deficit' => round($r->deficit, 2), 'lead_time_days' => $r->lead_time_days,
                'priority_score' => round($r->priority_score, 2), 'priority_level' => $r->priority_level,
            ])
            ->values()->all();
    }
}
