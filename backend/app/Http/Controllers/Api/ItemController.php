<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    /**
     * Real bug: this used to return Laravel's default paginator shape
     * (`meta.current_page`), but every other list page in this app — and the
     * shared frontend `Paginated<T>` type / `Pagination` component — expects
     * `meta.page`. With that key missing, the pager's "next" button computed
     * `undefined + 1 = NaN`, so it looked like paging (and therefore seeing
     * more than the first 25 of 9,177 items) simply didn't work.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $query = Item::filtered($request)->with([
            'category:id,name,path', 'unit:id,code,name', 'snapshot', 'effectiveSafetyStock',
            'defaultWarehouse:id,code,name', 'defaultLocation:id,code',
        ]);

        // sorting
        $sort = $request->string('sort', 'items.code')->value();
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $sortable = [
            'code' => 'items.code', 'description' => 'items.description',
            'lead_time_days' => 'items.lead_time_days',
            'priority_score' => 'snap.priority_score', 'selisih' => 'snap.selisih',
            'deficit' => 'snap.deficit', 'available' => 'snap.available',
        ];
        $query->orderBy($sortable[$column] ?? 'items.code', $direction);

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => ItemResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * All active item IDs matching the current Master Barang filter (search/kategori/UOM/status —
     * same scope as index(), just unpaginated). Used by "custom" stock-opname scheduling to bulk-add
     * every item in a filtered set (e.g. one whole category) without the picker having to page
     * through hundreds of results one screen at a time.
     */
    public function ids(Request $request): JsonResponse
    {
        $ids = Item::filtered($request)->where('items.is_active', true)->pluck('items.id');

        return response()->json(['data' => $ids]);
    }

    /**
     * Distinct Kategori Anak 1/2/3 combinations actually present (from
     * Accurate sync) — one row per real branch, e.g.
     * {anak_1: "AUTOMOTIVE WHEELS & TIRES", anak_2: "BAN LUAR (TIRES)", anak_3: "BAN LUAR BENANG (NYLON TIRES)"}.
     * The frontend builds a 3-level cascading filter from this flat list
     * client-side (same approach as CategoryPicker.tsx for the Excel tree).
     * accurate_category_induk is included per row too, but it's an
     * independent classification (derived from the item code prefix, not
     * Accurate's PARENTITEM chain — see AccurateSyncService::KATEGORI_INDUK_MAP)
     * so the frontend treats it as its own standalone filter, not cascaded
     * with anak_1/2/3.
     */
    public function accurateCategoryOptions(): JsonResponse
    {
        $options = Item::query()
            ->whereNotNull('accurate_category_anak_1')
            ->select('accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3', 'accurate_category_induk')
            ->distinct()
            ->orderBy('accurate_category_anak_1')
            ->orderBy('accurate_category_anak_2')
            ->orderBy('accurate_category_anak_3')
            ->get();

        return response()->json(['data' => $options]);
    }

    /** Lightweight search for the item picker (requests / PPB). */
    public function lookup(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->value();
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $items = Item::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhereLoose('description', $search))
            ->with(['unit:id,code', 'snapshot:item_id,available,stock_known'])
            ->orderBy('code')
            ->limit(15)
            ->get()
            ->map(fn (Item $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'description' => $i->description,
                'unit' => $i->unit?->code,
                'unit_id' => $i->unit_id,
                'default_warehouse_id' => $i->default_warehouse_id,
                'available' => $i->snapshot?->available,
                'stock_known' => (bool) ($i->snapshot?->stock_known ?? false),
            ]);

        return response()->json(['data' => $items]);
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load([
            'category', 'unit', 'snapshot', 'effectiveSafetyStock',
            'safetyStocks', 'aliases', 'inventory', 'defaultWarehouse', 'defaultLocation',
        ]));
    }

    /**
     * Histori satu barang — gabungan tiga sumber, diurutkan tanggal terbaru dulu:
     * MASUK (RI, vendor ada langsung di baris RI), KELUAR (NPBG, pelanggan — atau
     * nopol kalau NPBG itu dipakai oleh Tracking Ban Luar/Maintenance), dan ADJUST
     * (penyesuaian stock opname). Dicocokkan lewat items.code = ri/npbg.kode_barang
     * (ruang kode yang sama persis, lihat migrasi npbg.kode_barang) — bukan lewat
     * stock_movements, karena RI/NPBG adalah mirror Accurate read-only yang tidak
     * pernah menulis ke stock_movements (lihat StockLedgerService).
     */
    public function history(Request $request, Item $item): JsonResponse
    {
        $code = $item->code;

        $masuk = \App\Models\Ri::where('kode_barang', $code)
            ->select(['no_ri', 'tgl_ri', 'vendor', 'kuantitas', 'satuan', 'no_seri'])
            ->get()
            ->map(fn ($r) => [
                'type' => 'MASUK',
                'date' => $r->tgl_ri?->toDateString(),
                'doc_no' => $r->no_ri,
                'counterparty_label' => 'Vendor',
                'counterparty' => $r->vendor,
                'qty' => $r->kuantitas,
                'satuan' => $r->satuan,
                'no_seri' => $r->no_seri,
                'note' => null,
            ]);

        $npbgRows = \App\Models\Npbg::where('kode_barang', $code)
            ->select(['id', 'no_npbg', 'tgl_npbg', 'pelanggan', 'kuantitas', 'satuan', 'divisi', 'no_seri'])
            ->get();
        $npbgIds = $npbgRows->pluck('id');
        $nopolByNpbg = [];
        if ($npbgIds->isNotEmpty()) {
            foreach (\Illuminate\Support\Facades\DB::table('tyre_changes')->whereIn('out_npbg_id', $npbgIds)->whereNotNull('nopol_raw')->pluck('nopol_raw', 'out_npbg_id') as $npbgId => $nopol) {
                $nopolByNpbg[$npbgId] = $nopol;
            }
            foreach (\Illuminate\Support\Facades\DB::table('maintenance_order_subs')->whereIn('npbg_id', $npbgIds)->whereNotNull('nopol_raw')->pluck('nopol_raw', 'npbg_id') as $npbgId => $nopol) {
                $nopolByNpbg[$npbgId] ??= $nopol;
            }
        }
        $keluar = $npbgRows->map(function ($n) use ($nopolByNpbg) {
            $nopol = $nopolByNpbg[$n->id] ?? null;

            return [
                'type' => 'KELUAR',
                'date' => $n->tgl_npbg?->toDateString(),
                'doc_no' => $n->no_npbg,
                'counterparty_label' => $nopol ? 'Nopol' : 'Pelanggan',
                'counterparty' => $nopol ?: ($n->pelanggan ?: null),
                'qty' => $n->kuantitas,
                'satuan' => $n->satuan,
                'no_seri' => $n->no_seri,
                'note' => $n->divisi,
            ];
        });

        $adjust = \App\Models\StockMovement::where('item_id', $item->id)
            ->where('movement_type', 'STOCK_ADJUSTMENT')
            ->with('warehouse:id,code')
            ->get()
            ->map(fn ($m) => [
                'type' => 'ADJUST',
                'date' => $m->created_at?->toDateString(),
                'doc_no' => null,
                'counterparty_label' => 'Gudang',
                'counterparty' => $m->warehouse?->code,
                'qty' => $m->direction < 0 ? -$m->qty : $m->qty,
                'satuan' => null,
                'no_seri' => null,
                'note' => $m->note,
            ]);

        $summary = ['masuk' => $masuk->count(), 'keluar' => $keluar->count(), 'adjust' => $adjust->count()];

        $all = $masuk->concat($keluar)->concat($adjust)
            ->filter(fn ($r) => $r['date'] !== null)
            ->sortByDesc('date')
            ->values();

        // Chip "Masuk"/"Keluar"/"Penyesuaian" di atas tabel bisa diklik sebagai
        // filter — summary di atas tetap dihitung dari data penuh (sebelum
        // difilter) supaya angkanya tidak berubah saat salah satu chip aktif.
        if ($type = $request->string('type')->upper()->value()) {
            $all = $all->filter(fn ($r) => $r['type'] === $type)->values();
        }

        $perPage = min((int) $request->integer('per_page', 20), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $total = $all->count();
        $slice = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'meta' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max((int) ceil($total / $perPage), 1),
            ],
            'summary' => $summary,
        ]);
    }

    /** GET /api/items/options — the fixed choices of the item form: Kategori Induk / Anak 1 / 2 / 3, UOM. */
    public function options(): \Illuminate\Http\JsonResponse
    {
        $c = \App\Support\ItemOptions::categories();
        $units = \App\Models\Unit::whereIn('code', \App\Support\ItemOptions::UOM)->get(['id', 'code', 'name'])->keyBy('code');

        return response()->json(['data' => [
            'induk' => $c['accurate_category_induk'], 'anak_1' => $c['accurate_category_anak_1'],
            'anak_2' => $c['accurate_category_anak_2'], 'anak_3' => $c['accurate_category_anak_3'],
            // in the given order
            'uom' => collect(\App\Support\ItemOptions::UOM)->map(fn ($code) => $units->get($code))->filter()->values(),
        ]]);
    }

    public function store(StoreItemRequest $request): ItemResource
    {
        // refresh() (bukan fresh()) supaya default kolom DB ikut terbawa TANPA kehilangan
        // wasRecentlyCreated — itu yang membuat response status otomatis 201.
        $item = Item::create($request->validated());
        $item->refresh();
        // a new Barang starts with a known stock of 0 (a Jasa has none); Accurate's qty, if it ever has one, follows at the sync
        $warehouseId = \App\Models\Warehouse::query()->orderBy('id')->value('id');
        if ($item->product_kind !== 'JASA' && $warehouseId) {
            app(\App\Services\Inventory\StockLedgerService::class)->openingBalance($item->id, $warehouseId, 0, $request->user()?->id);
        }

        return new ItemResource($item->load('category', 'unit', 'defaultWarehouse', 'defaultLocation'));
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $item->update($request->validated());

        return new ItemResource($item->load('category', 'unit', 'snapshot', 'defaultWarehouse', 'defaultLocation'));
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Item dihapus.']);
    }
}
