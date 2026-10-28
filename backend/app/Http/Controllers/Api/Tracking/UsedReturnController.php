<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\UsedReturn;
use App\Models\UsedReturnItem;
use App\Services\Tracking\UsedReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsedReturnController extends Controller
{
    public function __construct(private readonly UsedReturnService $service) {}

    /**
     * Listed flat, one row per item line — same format as RI's own list
     * (App\Http\Controllers\Api\RiController::index()), per explicit user
     * instruction. Most Pengembalian Bekas rows are themselves derived
     * straight from RI (divisi NV, see AccurateSyncService::
     * deriveUsedReturnsFromRi()), so this mirrors that shape rather than
     * the header+item-count summary used elsewhere in this app.
     */
    public function index(Request $request): JsonResponse
    {
        $query = UsedReturnItem::query()
            ->join('used_returns', 'used_returns.id', '=', 'used_return_items.used_return_id')
            ->select(
                'used_return_items.*',
                'used_returns.number as ur_number',
                'used_returns.status as ur_status',
                'used_returns.return_date as ur_return_date',
                'used_returns.accurate_apinvoice_id as ur_accurate_apinvoice_id',
            )
            ->with(['item:id,code,description', 'unit:id,code']);

        if ($s = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($s) {
                $q->where('used_returns.number', 'like', "%{$s}%")
                    ->orWhereHas('item', fn ($q2) => $q2->where('code', 'like', "%{$s}%")->orWhereLoose('description', $s))
                    ->orWhereLoose('used_return_items.description_raw', $s);
            });
        }
        $request->whenFilled('status', fn ($v) => $query->where('used_returns.status', strtoupper((string) $v)));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('used_returns.return_date', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('used_returns.return_date', '<=', $v));

        $query->orderByDesc('used_returns.return_date')->orderByDesc('used_return_items.id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (UsedReturnItem $li) => $this->lineRow($li)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(UsedReturn $usedReturn): JsonResponse
    {
        $usedReturn->load('items.item:id,code', 'items.componentType:id,name', 'npbg:id,no_npbg', 'ri:id,number');

        return response()->json(['data' => $this->row($usedReturn) + [
            'npbg_number' => $usedReturn->npbg?->no_npbg,
            // "RI bekas (internal)" — the Receiving created once this is actually
            // closed (see UsedReturnService::close()). No separate "RI Accurate
            // asal" field: for a derived row, `number` above already IS the source
            // RI's own number (see AccurateSyncService::deriveUsedReturnsFromRi()),
            // so a second field showing the same value would be redundant.
            'ri_number' => $usedReturn->ri?->number,
            'note' => $usedReturn->note,
            'items' => $usedReturn->items->map(fn ($l) => [
                'id' => $l->id, 'item_code' => $l->item?->code,
                'component_type' => $l->componentType?->name,
                'description' => $l->description_raw, 'qty' => $l->qty,
                'condition' => $l->condition, 'into_stock' => $l->into_stock,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'npbg_id' => ['nullable', 'integer', 'exists:npbg,id'],
            'npbg_ref_raw' => ['nullable', 'string', 'max:60'],
            'return_date' => ['nullable', 'date'],
            'format' => ['nullable', 'in:COMPONENT_MATRIX,ITEM_LINE'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.component_type_id' => ['nullable', 'integer', 'exists:used_return_component_types,id'],
            'items.*.description_raw' => ['nullable', 'string', 'max:400'],
            'items.*.qty' => ['required', 'numeric'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.condition' => ['nullable', 'in:REUSABLE,SCRAP,DAMAGED,USED,reusable,scrap,damaged,used'],
            'items.*.into_stock' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->row($this->service->create($request->user(), $data)->loadCount('items'))], 201);
    }

    public function update(Request $request, UsedReturn $usedReturn): JsonResponse
    {
        $data = $request->validate([
            'npbg_ref_raw' => ['nullable', 'string', 'max:60'],
            'return_date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.component_type_id' => ['nullable', 'integer', 'exists:used_return_component_types,id'],
            'items.*.description_raw' => ['nullable', 'string', 'max:400'],
            'items.*.qty' => ['required_with:items', 'numeric'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.condition' => ['nullable', 'in:REUSABLE,SCRAP,DAMAGED,USED,reusable,scrap,damaged,used'],
            'items.*.into_stock' => ['sometimes', 'boolean'],
        ]);
        $this->service->update($usedReturn, $data);

        return $this->show($usedReturn->fresh());
    }

    public function destroy(UsedReturn $usedReturn): JsonResponse
    {
        $this->service->delete($usedReturn);

        return response()->json(['message' => 'Pengembalian bekas dihapus.']);
    }

    public function close(Request $request, UsedReturn $usedReturn): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'return_date' => ['nullable', 'date'],
        ]);
        $this->service->close($request->user(), $usedReturn, $data);

        return $this->show($usedReturn->fresh());
    }

    private function row(UsedReturn $u): array
    {
        return [
            'id' => $u->id, 'number' => $u->number, 'status' => $u->status, 'format' => $u->format,
            'npbg_ref' => $u->npbg_ref_raw, 'return_date' => $u->return_date?->toDateString(),
            'items_count' => $u->items_count, 'created_at' => $u->created_at,
            'from_accurate' => $u->accurate_apinvoice_id !== null,
        ];
    }

    /** One flat list row per used_return_item — id is the HEADER id (opens the detail/close modal), line_id is this row's own unique key. */
    private function lineRow(UsedReturnItem $li): array
    {
        return [
            'id' => $li->used_return_id,
            'line_id' => $li->id,
            'number' => $li->ur_number,
            'return_date' => $li->ur_return_date,
            'status' => $li->ur_status,
            'kode_barang' => $li->item?->code,
            'deskripsi_barang' => $li->description_raw ?: $li->item?->description,
            'kuantitas' => $li->qty,
            'satuan' => $li->unit?->code,
            'condition' => $li->condition,
            'into_stock' => $li->into_stock,
            'from_accurate' => $li->ur_accurate_apinvoice_id !== null,
        ];
    }
}
