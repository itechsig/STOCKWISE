<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Services\Inventory\SafetyStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SafetyStockController extends Controller
{
    public function __construct(private readonly SafetyStockService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ItemSafetyStock::query()->with('item:id,code,description,unit_id')->latest('id');

        $request->whenFilled('item_id', fn ($v) => $query->where('item_id', $v));
        $request->whenFilled('source_category', fn ($v) => $query->where('source_category', $v));
        if ($request->filled('is_effective')) {
            $query->where('is_effective', $request->boolean('is_effective'));
        }
        if ($request->filled('needs_review')) {
            $query->where('needs_review', $request->boolean('needs_review'));
        }
        if ($s = $request->string('search')->trim()->value()) {
            $query->whereHas('item', fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhereLoose('description', $s));
        }

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ItemSafetyStock $s) => $this->row($s)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(ItemSafetyStock $safetyStock): JsonResponse
    {
        $safetyStock->load('item:id,code,description,unit_id');

        return response()->json(['data' => $this->row($safetyStock)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'source_category' => ['nullable', 'string', 'max:60'],
            'period_label' => ['nullable', 'string', 'max:30'],
            // avg_usage_3m (not _1m) feeds the Safety Stock/MIN PR formula — a single
            // month is too noisy for items not picked up every month (confirmed
            // against real data: many legitimately-recurring items show zero usage
            // in any given 1-month window). avg_usage_1m/6m/12m stay informational.
            'avg_usage_1m' => ['nullable', 'numeric', 'min:0'],
            'avg_usage_3m' => ['required', 'numeric', 'min:0'],
            'avg_usage_6m' => ['nullable', 'numeric', 'min:0'],
            'avg_usage_12m' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['required', 'integer', 'min:0'],
            'effective_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $row = $this->service->create($data, $request->user()->id);
        $row->load('item:id,code,description,unit_id');

        return response()->json(['data' => $this->row($row)], 201);
    }

    public function update(Request $request, ItemSafetyStock $safetyStock): JsonResponse
    {
        $data = $request->validate([
            'source_category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'period_label' => ['sometimes', 'nullable', 'string', 'max:30'],
            'avg_usage_1m' => ['sometimes', 'numeric', 'min:0'],
            'avg_usage_3m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'avg_usage_6m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'avg_usage_12m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0'],
            'effective_date' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $row = $this->service->update($safetyStock, $data);
        $row->load('item:id,code,description,unit_id');

        return response()->json(['data' => $this->row($row)]);
    }

    public function destroy(ItemSafetyStock $safetyStock): JsonResponse
    {
        $this->service->delete($safetyStock);

        return response()->json(['message' => 'Safety stock dihapus.']);
    }

    public function resolveConflict(ItemSafetyStock $safetyStock): JsonResponse
    {
        $row = $this->service->resolveConflict($safetyStock);
        $row->load('item:id,code,description,unit_id');

        return response()->json(['data' => $this->row($row)]);
    }

    private function row(ItemSafetyStock $s): array
    {
        /** @var Item|null $item */
        $item = $s->item;

        return [
            'id' => $s->id,
            'item_id' => $s->item_id,
            'item_code' => $item?->code,
            'item_description' => $item?->description,
            'source_category' => $s->source_category,
            'period_label' => $s->period_label,
            'avg_usage_1m' => $s->avg_usage_1m,
            'avg_usage_3m' => $s->avg_usage_3m,
            'avg_usage_6m' => $s->avg_usage_6m,
            'avg_usage_12m' => $s->avg_usage_12m,
            'lead_time_days' => $s->lead_time_days,
            'sqrt_lt' => $s->sqrt_lt,
            'safety_stock' => $s->safety_stock,
            'min_pr' => $s->min_pr,
            'effective_date' => $s->effective_date?->toDateString(),
            'is_effective' => $s->is_effective,
            'needs_review' => $s->needs_review,
            'note' => $s->note,
        ];
    }
}
