<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard "Kartu Stock — Barang Masuk & Keluar" — GET /api/dashboard/stock-cards.
 *
 * Report of the manual stock cards per day / week / month, optionally for one
 * project and one item. Items have different units, so over several items the
 * chart counts transaction lines; for one item it sums the quantities. The per-item
 * table always gives quantities with their unit.
 *
 * Periods use the company calendar (WIB) and include the running day / week / month,
 * since this is a progress report. Grouping is done in PHP so the queries stay
 * portable (MySQL + the SQLite test DB).
 */
class StockCardDashboardController extends Controller
{
    /** period => number of buckets */
    private const PERIODS = ['daily' => 30, 'weekly' => 12, 'monthly' => 12];

    public function __invoke(Request $request): JsonResponse
    {
        StockCardController::canView($request);
        $data = $request->validate([
            'period' => ['nullable', 'in:daily,weekly,monthly'],
            'project_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
        ]);
        $period = $data['period'] ?? 'daily';
        $projectId = $data['project_id'] ?? null;
        $itemId = $data['item_id'] ?? null;

        $buckets = $this->buckets($period, now('Asia/Jakarta')->startOfDay());
        $from = $buckets[0]['start'];
        $to = end($buckets)['end'];

        // cards in scope (project filter); the item filter narrows the lines, not the item list
        $cards = DB::table('stock_cards')->whereNotNull('project_id')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get(['id', 'item_id']);
        $cardIds = $cards->pluck('id');
        $lineCards = $itemId ? $cards->where('item_id', $itemId)->pluck('id') : $cardIds;

        $lines = DB::table('stock_card_entries')->whereIn('stock_card_id', $lineCards)
            ->whereBetween('entry_date', [$from, $to])
            ->get(['stock_card_id', 'entry_date', 'qty_in', 'qty_out']);
        $itemOf = $cards->pluck('item_id', 'id');

        $series = collect($buckets)->mapWithKeys(fn ($b) => [$b['key'] => $b + ['qty_in' => 0.0, 'qty_out' => 0.0, 'lines_in' => 0, 'lines_out' => 0]])->all();
        $perItem = [];
        foreach ($lines as $l) {
            $key = $this->keyOf($period, Carbon::parse(substr((string) $l->entry_date, 0, 10)));
            if (! isset($series[$key])) {
                continue;
            }
            $in = (float) $l->qty_in;
            $out = (float) $l->qty_out;
            $series[$key]['qty_in'] += $in;
            $series[$key]['qty_out'] += $out;
            $series[$key]['lines_in'] += $in > 0 ? 1 : 0;
            $series[$key]['lines_out'] += $out > 0 ? 1 : 0;
            $item = $itemOf[$l->stock_card_id];
            $perItem[$item] ??= ['qty_in' => 0.0, 'qty_out' => 0.0, 'lines' => 0];
            $perItem[$item]['qty_in'] += $in;
            $perItem[$item]['qty_out'] += $out;
            $perItem[$item]['lines']++;
        }

        // balance now (all dates) per item over the cards in scope
        $balance = DB::table('stock_card_entries')->whereIn('stock_card_id', $cardIds)
            ->selectRaw('stock_card_id, SUM(qty_in) - SUM(qty_out) AS sisa')->groupBy('stock_card_id')->get()
            ->groupBy(fn ($r) => $itemOf[$r->stock_card_id])->map(fn ($g) => (float) $g->sum('sisa'));

        $items = DB::table('items')->leftJoin('units', 'units.id', '=', 'items.unit_id')
            ->whereIn('items.id', $cards->pluck('item_id')->unique())->orderBy('items.code')
            ->get(['items.id', 'items.code', 'items.description', 'units.code as unit']);
        $rows = $items->filter(fn ($i) => isset($perItem[$i->id]))->map(fn ($i) => [
            'item_id' => $i->id, 'code' => $i->code, 'description' => $i->description, 'unit' => $i->unit,
            'qty_in' => round($perItem[$i->id]['qty_in'], 4), 'qty_out' => round($perItem[$i->id]['qty_out'], 4),
            'lines' => $perItem[$i->id]['lines'], 'sisa' => round($balance[$i->id] ?? 0, 4),
        ])->sortByDesc(fn ($r) => $r['qty_out'] + $r['qty_in'])->values();

        $series = array_values(array_map(fn ($s) => ['qty_in' => round($s['qty_in'], 4), 'qty_out' => round($s['qty_out'], 4)] + $s, $series));

        return response()->json(['data' => [
            'period' => $period, 'from' => $from, 'to' => $to,
            // over several items only line counts can be added up
            'measure' => $itemId ? 'qty' : 'lines',
            'unit' => $itemId ? $items->firstWhere('id', $itemId)?->unit : null,
            'series' => $series,
            'totals' => [
                'lines_in' => array_sum(array_column($series, 'lines_in')),
                'lines_out' => array_sum(array_column($series, 'lines_out')),
                'qty_in' => $itemId ? round(array_sum(array_column($series, 'qty_in')), 4) : null,
                'qty_out' => $itemId ? round(array_sum(array_column($series, 'qty_out')), 4) : null,
                'items_moved' => $rows->count(),
                'negative_items' => $balance->filter(fn ($s) => $s < 0)->count(),
            ],
            'items' => $rows->all(),
            'filters' => [
                'projects' => DB::table('projects')->whereIn('id', DB::table('stock_cards')->whereNotNull('project_id')->select('project_id'))
                    ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 ELSE 1 END")->orderBy('name')->get(['id', 'name', 'code', 'status']),
                'items' => $items->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'description' => $i->description, 'unit' => $i->unit])->values(),
            ],
        ]]);
    }

    /** @return list<array{key:string, start:string, end:string}> oldest first, the running one last */
    private function buckets(string $period, Carbon $today): array
    {
        $n = self::PERIODS[$period];
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            [$start, $end] = match ($period) {
                'daily' => [$today->copy()->subDays($i), $today->copy()->subDays($i)],
                'weekly' => [$s = $today->copy()->startOfWeek(Carbon::MONDAY)->subWeeks($i), $s->copy()->addDays(6)],
                'monthly' => [$s = $today->copy()->startOfMonth()->subMonthsNoOverflow($i), $s->copy()->endOfMonth()->startOfDay()],
            };
            $out[] = ['key' => $this->keyOf($period, $start), 'start' => $start->toDateString(), 'end' => $end->toDateString()];
        }

        return $out;
    }

    private function keyOf(string $period, Carbon $date): string
    {
        return match ($period) {
            'daily' => $date->toDateString(),
            'weekly' => $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'monthly' => $date->format('Y-m'),
        };
    }
}
