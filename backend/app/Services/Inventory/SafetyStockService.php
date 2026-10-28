<?php

namespace App\Services\Inventory;

use App\Models\ItemSafetyStock;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Manual CRUD for item_safety_stocks — admin input replacing the "SAFETY STOCK *" Excel
 * sheets. Formula (per explicit user instruction — replaces an earlier formula that
 * multiplied avg usage by SQRT(lead_time/30), which had no basis: that shape only
 * makes sense converting a standard deviation between periods, not an average):
 *
 *   Lead Time Demand = avg monthly usage × (lead_time_days / 30)
 *   Safety Stock      = ROUNDUP(2.33 × Lead Time Demand, 0)
 *   MIN PR            = ROUNDUP(Lead Time Demand + Safety Stock, 0)
 *
 * 2.33 = fixed service-level/buffer factor. 30 = assumed days/month. No standard
 * deviation — same formula for every item, regardless of category. Missing/null
 * usage or lead_time_days is treated as 0 (never guessed).
 * See PHASE-13 report / conversation for the worked example this was verified against:
 * avg=0.5, LT=5 -> demand=0.08333 -> SS=1 -> MIN PR=2.
 *
 * "avg monthly usage" = avg_usage_3m (NOT avg_usage_1m) — per explicit user decision
 * after reviewing real data: a strict 1-month window reads as zero for items that are
 * genuinely recurring but not picked up every single month, understating their real
 * Safety Stock need. avg_usage_1m/6m/12m are still computed and stored (informational).
 */
class SafetyStockService
{
    /** Service-level/buffer factor (brief: "2,33 = faktor buffer/service level"). */
    public const Z_FACTOR = 2.33;

    /** Assumed days per month for the monthly-usage -> daily-equivalent conversion. */
    public const DAYS_PER_MONTH = 30;

    /**
     * Pure formula — no I/O, easy to unit test in isolation. Guards against float
     * drift landing exactly on an integer boundary before ceil() (e.g. a true 2.0
     * computed as 2.0000000000003 must not round up to 3).
     *
     * @param  ?float  $avgMonthlyUsage  average monthly usage rate feeding the
     *                                   formula — the caller decides which window
     *                                   (this class uses avg_usage_3m, see above)
     * @return array{lead_time_demand: float, sqrt_lt: float, safety_stock: float, min_pr: float}
     */
    public static function calculate(?float $avgMonthlyUsage, ?int $leadTimeDays): array
    {
        $avg = $avgMonthlyUsage ?? 0.0;
        $leadTime = $leadTimeDays ?? 0;

        $leadTimeDemand = $avg * ($leadTime / self::DAYS_PER_MONTH);
        $safetyStock = self::roundUp(self::Z_FACTOR * $leadTimeDemand);
        $minPr = self::roundUp($leadTimeDemand + $safetyStock);

        return [
            'lead_time_demand' => $leadTimeDemand,
            // Kept as an informational figure only (Master Barang field "√LT") —
            // no longer part of the Safety Stock/MIN PR formula itself.
            'sqrt_lt' => round(sqrt($leadTime / self::DAYS_PER_MONTH), 4),
            'safety_stock' => $safetyStock,
            'min_pr' => $minPr,
        ];
    }

    private static function roundUp(float $value): float
    {
        $epsilon = 1e-9;
        $rounded = ceil($value - $epsilon);

        // ceil() on a value just under 0 (e.g. 0 - 1e-9) returns float -0.0,
        // which renders as "-0" in JSON/CLI output — same numeric value as
        // 0.0 (-0.0 == 0.0 is true in PHP) but confusing to show to a user.
        return $rounded === 0.0 ? 0.0 : $rounded;
    }

    public function create(array $data, ?int $actingUserId = null): ItemSafetyStock
    {
        return DB::transaction(function () use ($data, $actingUserId) {
            $row = new ItemSafetyStock($this->fillable($data));
            $this->applyFormula($row);
            $row->is_effective = ! ItemSafetyStock::query()->where('item_id', $data['item_id'])->exists();
            $row->needs_review = ! $row->is_effective;
            $row->save();

            if ($row->needs_review) {
                NotificationDispatcher::toPermission(
                    'item.safety_stock.resolve_conflict', 'safety_stock', 'warning',
                    'Safety Stock perlu ditinjau',
                    "Barang {$row->item?->code} punya data Safety Stock yang bentrok, perlu diselesaikan.",
                    '/safety-stocks',
                    exceptUserId: $actingUserId,
                );
            }

            return $row;
        });
    }

    public function update(ItemSafetyStock $safetyStock, array $data): ItemSafetyStock
    {
        $safetyStock->fill($this->fillable($data));
        $this->applyFormula($safetyStock);
        $safetyStock->save();

        return $safetyStock;
    }

    public function delete(ItemSafetyStock $safetyStock): void
    {
        $itemId = $safetyStock->item_id;
        $wasEffective = $safetyStock->is_effective;
        $safetyStock->delete();

        if ($wasEffective) {
            $next = ItemSafetyStock::query()->where('item_id', $itemId)->orderByDesc('safety_stock')->first();
            $next?->update(['is_effective' => true, 'needs_review' => false]);
        }
    }

    public function resolveConflict(ItemSafetyStock $safetyStock): ItemSafetyStock
    {
        DB::transaction(function () use ($safetyStock) {
            ItemSafetyStock::query()->where('item_id', $safetyStock->item_id)
                ->where('id', '!=', $safetyStock->id)
                ->update(['is_effective' => false, 'needs_review' => true]);

            $safetyStock->update(['is_effective' => true, 'needs_review' => false]);
        });

        return $safetyStock->fresh();
    }

    /**
     * Monthly usage averages per item code, from real Accurate-sourced NPBG history
     * (brief: "rata-rata 1/3/6/12 bulan tetap dihitung dari data pengeluaran aktual")
     * — not a stale, hand-entered Excel figure. One aggregate query (grouped by
     * kode_barang, single scan of the last 12 months) rather than 4 queries per item,
     * since this runs across the whole catalog in RecalculateSafetyStockCommand.
     *
     * @return \Illuminate\Support\Collection<string, array{avg_usage_1m: float, avg_usage_3m: float, avg_usage_6m: float, avg_usage_12m: float}>
     */
    public function usageAveragesFromNpbg(?\Carbon\Carbon $asOf = null): \Illuminate\Support\Collection
    {
        $asOf = ($asOf ?? now())->endOfDay();
        $since1m = $asOf->copy()->subMonthsNoOverflow(1)->startOfDay();
        $since3m = $asOf->copy()->subMonthsNoOverflow(3)->startOfDay();
        $since6m = $asOf->copy()->subMonthsNoOverflow(6)->startOfDay();
        $since12m = $asOf->copy()->subMonthsNoOverflow(12)->startOfDay();

        return DB::table('npbg')
            ->whereNotNull('kode_barang')
            ->where('tgl_npbg', '>=', $since12m)
            ->where('tgl_npbg', '<=', $asOf)
            ->groupBy('kode_barang')
            ->selectRaw(
                // In base units (items.unit): an NPBG line in SET/BOX/PCK is
                // kuantitas × unit_ratio base units — the same figure Accurate
                // moves stock by (verified against ITEMHIST).
                'kode_barang,
                 SUM(CASE WHEN tgl_npbg >= ? THEN kuantitas * COALESCE(unit_ratio, 1) ELSE 0 END) as total_1m,
                 SUM(CASE WHEN tgl_npbg >= ? THEN kuantitas * COALESCE(unit_ratio, 1) ELSE 0 END) as total_3m,
                 SUM(CASE WHEN tgl_npbg >= ? THEN kuantitas * COALESCE(unit_ratio, 1) ELSE 0 END) as total_6m,
                 SUM(kuantitas * COALESCE(unit_ratio, 1)) as total_12m',
                [$since1m, $since3m, $since6m]
            )
            ->get()
            ->keyBy('kode_barang')
            ->map(fn ($row) => [
                'avg_usage_1m' => round(((float) $row->total_1m) / 1, 2),
                'avg_usage_3m' => round(((float) $row->total_3m) / 3, 2),
                'avg_usage_6m' => round(((float) $row->total_6m) / 6, 2),
                'avg_usage_12m' => round(((float) $row->total_12m) / 12, 2),
            ]);
    }

    private function fillable(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'item_id', 'source_category', 'period_label',
            'avg_usage_1m', 'avg_usage_3m', 'avg_usage_6m', 'avg_usage_12m',
            'lead_time_days', 'effective_date', 'note',
        ]));
    }

    private function applyFormula(ItemSafetyStock $row): void
    {
        // avg_usage_3m, not _1m — per explicit user decision: a single month is too
        // noisy for items not picked up every month (many legitimately-recurring
        // items showed zero usage in a strict 1-month window against real data).
        // avg_usage_1m/6m/12m remain stored as informational figures only.
        $result = self::calculate($row->avg_usage_3m, $row->lead_time_days);

        $row->sqrt_lt = $result['sqrt_lt'];
        $row->safety_stock = $result['safety_stock'];
        $row->min_pr = $result['min_pr'];
    }
}
