<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Item;
use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\StockwiseEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = Inventory::query()
            ->with(['item:id,code,description,unit_id', 'item.unit:id,code', 'warehouse:id,code,name'])
            ->join('items', 'items.id', '=', 'inventory.item_id');

        $request->whenFilled('warehouse_id', fn ($v) => $query->where('inventory.warehouse_id', $v));
        $request->whenFilled('item_id', fn ($v) => $query->where('inventory.item_id', $v));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('items.code', 'like', "%{$s}%")->orWhereLoose('items.description', $s));
        }
        $request->whenFilled('stock_known', fn ($v) => $query->where('inventory.stock_known', filter_var($v, FILTER_VALIDATE_BOOL)));

        $page = $query->select('inventory.*')->orderBy('items.code')->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Inventory $i) => [
                'id' => $i->id,
                'item' => ['id' => $i->item->id, 'code' => $i->item->code, 'description' => $i->item->description,
                    'unit' => $i->item->unit?->code],
                'warehouse' => ['id' => $i->warehouse->id, 'code' => $i->warehouse->code],
                'actual_qty' => $i->actual_qty,
                'reserved_qty' => $i->reserved_qty,
                'available_qty' => $i->available_qty,
                'stock_known' => $i->stock_known,
                'last_counted_at' => $i->last_counted_at,
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Item $item): JsonResponse
    {
        return response()->json([
            'data' => [
                'item' => $item->only('id', 'code', 'description'),
                'inventory' => $item->inventory()->with('warehouse:id,code,name')->get()->map(fn ($i) => [
                    'warehouse' => $i->warehouse->only('id', 'code', 'name'),
                    'actual_qty' => $i->actual_qty,
                    'reserved_qty' => $i->reserved_qty,
                    'available_qty' => $i->available_qty,
                    'stock_known' => $i->stock_known,
                ]),
                'movements' => $item->movements()->with('warehouse:id,code')
                    ->latest('created_at')->limit(50)->get()->map(fn ($m) => [
                        'type' => $m->movement_type,
                        'warehouse' => $m->warehouse->code,
                        'qty' => $m->qty,
                        'actual_before' => $m->actual_before,
                        'actual_after' => $m->actual_after,
                        'note' => $m->note,
                        'created_at' => $m->created_at,
                    ]),
            ],
        ]);
    }

    /**
     * Always freshly computed from the live item_safety_stocks/inventory data —
     * no cached snapshot, no manual "Hitung ulang" step (removed per user decision
     * 2026-10-09: the list must be real-time). See InventoryAnalyzer::compute().
     */
    public function analysis(Request $request, InventoryAnalyzer $analyzer): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 50), 200);
        $page = max((int) $request->integer('page', 1), 1);

        ['rows' => $rows, 'run' => $run] = $analyzer->filtered($request);

        $total = $rows->count();
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new LengthAwarePaginator($slice, $total, $perPage, $page);
        // already on its way (Draft PPB / PPB / PO) — Restock buys only what is still missing
        $onOrder = \App\Services\Inventory\OnOrder::byItem($slice->pluck('item_id')->all());

        return response()->json([
            'data' => $slice->map(fn ($r) => $this->analysisRow($r) + ['on_order' => $onOrder[$r->item_id] ?? null]),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
            'run' => $run + ['computed_at' => now()],
        ]);
    }

    private function analysisRow(object $r): array
    {
        return [
            'item' => ['id' => $r->item_id, 'code' => $r->item_code, 'description' => $r->item_description, 'unit' => $r->uom],
            'actual' => $r->actual, 'reserved' => $r->reserved, 'available' => $r->available,
            'stock_known' => $r->stock_known,
            'safety_stock' => $r->safety_stock, 'min_pr' => $r->min_pr, 'lead_time_days' => $r->lead_time_days,
            'avg_usage_1m' => $r->avg_usage_1m, 'avg_usage_3m' => $r->avg_usage_3m,
            'avg_usage_6m' => $r->avg_usage_6m, 'avg_usage_12m' => $r->avg_usage_12m,
            'selisih' => $r->selisih, 'status' => $r->status, 'deficit' => $r->deficit,
            'priority_score' => $r->priority_score, 'priority_level' => $r->priority_level,
            'recommendation' => $r->recommendation, 'recommended_qty' => $r->recommended_qty,
        ];
    }

    /** POST body: { lines: [{ item_id, warehouse_id, qty }] } -> projected stock + warning. */
    public function projected(Request $request, StockwiseEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['lines'])->map(function ($line) {
            $item = Item::with('effectiveSafetyStock')->find($line['item_id']);

            $invQuery = $item->inventory();
            if (! empty($line['warehouse_id'])) {
                $invQuery->where('warehouse_id', $line['warehouse_id']);
            }
            $actual = (float) $invQuery->sum('actual_qty');
            $reserved = (float) $invQuery->sum('reserved_qty');
            $available = $actual - $reserved;
            $safety = (float) ($item->effectiveSafetyStock?->safety_stock ?? 0);
            $requested = (float) $line['qty'];
            $projected = $available - $requested;
            $below = $projected < $safety;

            return [
                'item_id' => $item->id,
                'code' => $item->code,
                'actual' => $actual, 'reserved' => $reserved, 'available' => $available,
                'safety_stock' => $safety,
                'lead_time_days' => $item->lead_time_days,
                'requested_qty' => $requested,
                'projected_stock' => $projected,
                'below_safety' => $below,
                'insufficient' => $projected < 0,
                'warning' => $below
                    ? 'Request ini akan menyebabkan stok berada di bawah Safety Stock.'
                    : null,
            ];
        });

        return response()->json(['data' => $lines]);
    }
}
