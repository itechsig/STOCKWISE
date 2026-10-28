<?php

namespace App\Http\Controllers\Api\Tracking;

use App\Http\Controllers\Controller;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderSub;
use App\Services\DocumentNumberService;
use App\Services\Tracking\ManufacturingService;
use App\Support\ManufacturingExcel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manufaktur & Assembly — one feature, two Excel sheets: kind ASSEMBLY = sheet
 * "Manufaktur & Assembly" (MA), kind JASA = sheet "Manufaktur & Jasa Lain-Lain" (MJ).
 */
class ManufacturingController extends Controller
{
    public function __construct(private readonly ManufacturingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ManufacturingOrder::query()->with('vendor:id,name')->withCount('subs')->latest('date')->latest('id');
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        $request->whenFilled('kind', fn ($v) => $query->where('kind', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('product_name', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ManufacturingOrder $o) => $this->row($o)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * The Excel sheet itself: one row per unit, newest first. `id` is the order
     * (No. MA / MJ) so a click opens its detail; `line_id` is the row; `no` is
     * the Excel "No".
     */
    public function lines(Request $request): JsonResponse
    {
        $kind = strtoupper($request->string('kind', 'ASSEMBLY')->value()) === 'JASA' ? 'JASA' : 'ASSEMBLY';
        $query = DB::table('manufacturing_order_subs as s')
            ->join('manufacturing_orders as o', 'o.id', '=', 's.manufacturing_order_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'o.vendor_id')
            ->leftJoin('items as i', 'i.id', '=', 's.item_id')
            ->where('o.kind', $kind)
            ->select('s.*', 'o.number', 'o.kind', 'o.date', 'o.location', 'o.product_name as order_product', 'v.name as vendor_name', 'i.code as item_code')
            ->orderByDesc('s.sheet_no')->orderByDesc('s.id');

        $request->whenFilled('status', fn ($v) => $query->where('s.status', strtoupper(str_replace('-', '_', (string) $v))));
        if ($q = $request->string('search')->trim()->value()) {
            $query->where(fn ($w) => $w->where('o.number', 'like', "%{$q}%")
                ->orWhere('s.product_name', 'like', "%{$q}%")->orWhere('o.product_name', 'like', "%{$q}%")
                ->orWhere('s.serial_no_raw', 'like', "%{$q}%")->orWhere('s.process', 'like', "%{$q}%")
                ->orWhere('s.npbg_no', 'like', "%{$q}%")->orWhere('s.ri_no', 'like', "%{$q}%")
                ->orWhere('s.row_location', 'like', "%{$q}%")->orWhere('o.location', 'like', "%{$q}%")
                ->orWhere('s.note_start', 'like', "%{$q}%")->orWhere('s.note_end', 'like', "%{$q}%")
                ->orWhere('s.file_name', 'like', "%{$q}%"));
        }
        $page = $query->paginate(min((int) $request->integer('per_page', 30), 100))->withQueryString();
        $rows = collect($page->items());
        $serialLinks = $this->serialLinks($rows->pluck('serial_no_raw'));

        return response()->json([
            'data' => $rows->map(fn ($s) => [
                'id' => (int) $s->manufacturing_order_id, 'line_id' => (int) $s->id,
            ] + $this->present((object) [
                'sheet_no' => $s->sheet_no, 'row_date' => $s->row_date, 'order_date' => $s->date, 'number' => $s->number, 'kind' => $s->kind,
                'sub_no' => $s->sub_no, 'row_location' => $s->row_location, 'order_location' => $s->location ?? $s->vendor_name,
                'product_name' => $s->product_name ?? $s->order_product, 'item_code' => $s->item_code,
                'serial_no_raw' => $s->serial_no_raw, 'item_no' => $s->item_no, 'process' => $s->process, 'note_start' => $s->note_start,
                'status' => $s->status, 'npbg_no' => $s->npbg_no, 'npbg_id' => $s->npbg_id, 'file_name' => $s->file_name,
                'photo_path' => $s->photo_path, 'finish_date' => $s->finish_date, 'ri_no' => $s->ri_no, 'ri_id' => $s->ri_id,
                'note_end' => $s->note_end, 'source' => $s->source,
            ], $serialLinks)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                // Excel row 4: =TODAY() and the next MA/MJ number
                'today' => now('Asia/Jakarta')->toDateString(),
                'next_number' => app(DocumentNumberService::class)->peekShort($kind === 'JASA' ? 'MJ' : 'MA', now('Asia/Jakarta'))],
        ]);
    }

    public function show(ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $o = $manufacturingOrder->load(['vendor:id,name', 'subs' => fn ($q) => $q->with('item:id,code')->orderBy('sheet_no')->orderBy('sub_no')->orderBy('item_no')->orderBy('id')]);
        $refs = app(\App\Services\Tracking\AccurateDocRefs::class);
        $serialLinks = $this->serialLinks($o->subs->pluck('serial_no_raw'));

        return response()->json(['data' => $this->row($o) + [
            'subs' => $o->subs->map(fn ($s) => ['id' => $s->id] + $this->present((object) [
                'sheet_no' => $s->sheet_no, 'row_date' => $s->row_date, 'order_date' => $o->date, 'number' => $o->number, 'kind' => $o->kind,
                'sub_no' => $s->sub_no, 'row_location' => $s->row_location, 'order_location' => $o->location ?? $o->vendor?->name,
                'product_name' => $s->product_name ?? $o->product_name, 'item_code' => $s->item?->code,
                'serial_no_raw' => $s->serial_no_raw, 'item_no' => $s->item_no, 'process' => $s->process, 'note_start' => $s->note_start,
                'status' => $s->status, 'npbg_no' => $s->npbg_no, 'npbg_id' => $s->npbg_id, 'file_name' => $s->file_name,
                'photo_path' => $s->photo_path, 'finish_date' => $s->finish_date, 'ri_no' => $s->ri_no, 'ri_id' => $s->ri_id,
                'note_end' => $s->note_end, 'source' => $s->source,
            ], $serialLinks) + [
                'npbg' => $s->npbg_id ? $refs->npbgSummary($s->npbg_no) : null,
                'ri' => $s->ri_id ? $refs->riSummary($s->ri_no) : null,
            ]),
        ]]);
    }

    /**
     * Choices for the inputs — the Excel "Dropdown List" per sheet: values already
     * used on that sheet, plus what the database knows. With `q`, also Accurate
     * items (Hasil Produk), vendors (Lokasi) and tyre / STPP serials (No. Seri)
     * matching it.
     */
    public function options(Request $request): JsonResponse
    {
        $kind = strtoupper($request->string('kind', 'ASSEMBLY')->value()) === 'JASA' ? 'JASA' : 'ASSEMBLY';
        $q = $request->string('q')->trim()->value();
        $field = $request->string('field')->value();
        $used = fn (string $col) => DB::table('manufacturing_order_subs as s')
            ->join('manufacturing_orders as o', 'o.id', '=', 's.manufacturing_order_id')
            ->where('o.kind', $kind)->whereNotNull("s.{$col}")->where("s.{$col}", '<>', '')
            ->when($q !== '' && $field !== '', fn ($w) => $w->where("s.{$col}", 'like', "%{$q}%"))
            ->selectRaw("s.{$col} as v, count(*) n")->groupBy("s.{$col}")->orderByDesc('n')->limit(300)->pluck('v');

        if ($q === '' || $field === '') {
            return response()->json(['data' => [
                'products' => $used('product_name'), 'serials' => $used('serial_no_raw'),
                'processes' => $used('process'), 'locations' => $used('row_location'),
            ]]);
        }

        $extra = match ($field) {
            'product' => \App\Models\Item::query()->where('is_active', true)
                ->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->orderBy('description')->limit(20)->pluck('description'),
            'location' => \App\Models\Vendor::query()->where('name', 'like', "%{$q}%")->orderBy('name')->limit(20)->pluck('name'),
            'serial' => DB::table('tyre_changes')->where('new_serial_raw', 'like', "%{$q}%")->limit(20)->pluck('new_serial_raw')
                ->concat(DB::table('tyre_changes')->where('old_serial_raw', 'like', "%{$q}%")->limit(20)->pluck('old_serial_raw'))
                ->concat(DB::table('stpp_transactions')->where('serial_no_raw', 'like', "%{$q}%")->limit(20)->pluck('serial_no_raw')),
            default => collect(),
        };
        $col = ['product' => 'product_name', 'serial' => 'serial_no_raw', 'process' => 'process', 'location' => 'row_location'][$field] ?? null;

        return response()->json(['data' => ($col ? $used($col) : collect())->concat($extra)->filter()->unique()->values()->take(40)]);
    }

    /** One Excel row, same shape for the sheet list and the detail. */
    private function present(object $s, array $serialLinks): array
    {
        $d = fn ($v) => $v ? substr($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, 0, 10) : null;
        $itemNo = $s->item_no !== null ? (int) $s->item_no : null;

        return [
            'no' => $s->sheet_no !== null ? (int) $s->sheet_no : null,
            'date' => $d($s->row_date) ?? $d($s->order_date),
            'number' => $s->number, 'kind' => $s->kind, 'sub_no' => $s->sub_no,
            'location' => $s->row_location ?? $s->order_location,
            'product_name' => $s->product_name,
            // Hasil Produk = an Accurate item → link to Barang
            'item_code' => $s->item_code,
            'serial_no' => $s->serial_no_raw,
            // No. Seri found in Tracking Ban Luar / STPP → link there
            'serial_link' => $serialLinks[mb_strtoupper(trim((string) $s->serial_no_raw))] ?? null,
            'item_no' => $itemNo, 'process' => $s->process, 'note_start' => $s->note_start, 'status' => $s->status,
            // linked only when the document is in the Accurate data (older ones stay text)
            'npbg_no' => $s->npbg_no, 'npbg_linked' => (bool) $s->npbg_id,
            'file_name' => $s->file_name,
            // Excel "Helper Nama File" — what Nama File should be
            'helper_file_name' => ManufacturingExcel::fileName($s->kind, $s->number, $s->sub_no, $s->product_name, $s->serial_no_raw, $itemNo),
            'has_photo' => (bool) $s->photo_path,
            'finish_date' => $d($s->finish_date),
            'ri_no' => $s->ri_no, 'ri_linked' => (bool) $s->ri_id,
            'note_end' => $s->note_end, 'source' => $s->source,
        ];
    }

    /** @return array<string, string> serial (upper) => page that lists it */
    private function serialLinks(\Illuminate\Support\Collection $serials): array
    {
        $list = $serials->filter(fn ($v) => $v !== null && trim($v) !== '' && trim($v) !== '-')->map(fn ($v) => trim($v))->unique()->values();
        if ($list->isEmpty()) {
            return [];
        }
        $links = [];
        foreach (DB::table('stpp_transactions')->whereIn('serial_no_raw', $list)->pluck('serial_no_raw') as $sn) {
            $links[mb_strtoupper($sn)] = '/stpp?search='.rawurlencode($sn);
        }
        foreach (['new_serial_raw', 'old_serial_raw'] as $col) {
            foreach (DB::table('tyre_changes')->whereIn($col, $list)->pluck($col) as $sn) {
                $links[mb_strtoupper($sn)] = '/tyre-changes?search='.rawurlencode($sn);
            }
        }

        return $links;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:ASSEMBLY,JASA,assembly,jasa'],
            'date' => ['nullable', 'date'],
            'product_name' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:150'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'lines' => ['nullable', 'array', 'max:200'],
            ...$this->rowRules('lines.*.'),
        ]);

        return response()->json(['data' => $this->row($this->service->createOrder($request->user(), $data)->loadCount('subs'))], 201);
    }

    public function update(Request $request, ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'product_name' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:150'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ]);
        $this->service->updateOrder($manufacturingOrder, $data);

        return $this->show($manufacturingOrder->fresh());
    }

    public function destroy(ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $this->service->deleteOrder($manufacturingOrder);

        return response()->json(['message' => 'Nomor dihapus.']);
    }

    public function addSub(Request $request, ManufacturingOrder $manufacturingOrder): JsonResponse
    {
        $data = $request->validate([
            ...$this->rowRules(),
            'sub_no' => ['nullable', 'string', 'regex:/^SUB-\d{2}$/i'],
            'new_sub' => ['nullable', 'boolean'],
        ]);
        $this->service->addSub($manufacturingOrder, $data);

        return $this->show($manufacturingOrder->fresh());
    }

    public function updateSub(Request $request, ManufacturingOrderSub $sub): JsonResponse
    {
        $this->service->updateSub($sub, $request->validate($this->rowRules()));

        return $this->show($sub->order()->first());
    }

    public function destroySub(ManufacturingOrderSub $sub): JsonResponse
    {
        $order = $sub->order;
        $this->service->deleteSub($sub);

        return $this->show($order->fresh());
    }

    public function completeSub(Request $request, ManufacturingOrderSub $sub): JsonResponse
    {
        $data = $request->validate([
            'finish_date' => ['nullable', 'date'],
            'note_end' => ['nullable', 'string', 'max:2000'],
            'ri_no' => ['nullable', 'string', 'max:40'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'string', 'max:15000000'],
        ]);
        $this->service->completeSub($sub, $data);

        return $this->show($sub->order()->first());
    }

    public function uploadPhoto(Request $request, ManufacturingOrderSub $sub): JsonResponse
    {
        $data = $request->validate(['photo' => ['required', 'string', 'max:15000000']]);
        $this->service->setPhoto($sub, $data['photo']);

        return $this->show($sub->order()->first());
    }

    public function photo(ManufacturingOrderSub $sub): StreamedResponse
    {
        abort_unless($sub->photo_path && Storage::disk('local')->exists($sub->photo_path), 404);

        return Storage::disk('local')->response($sub->photo_path);
    }

    /** Excel columns of one unit row. */
    private function rowRules(string $prefix = ''): array
    {
        return [
            "{$prefix}row_date" => ['nullable', 'date'],
            "{$prefix}row_location" => ['nullable', 'string', 'max:150'],
            "{$prefix}file_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}product_name" => ['nullable', 'string', 'max:200'],
            "{$prefix}serial_no_raw" => ['nullable', 'string', 'max:80'],
            "{$prefix}item_no" => ['nullable', 'integer', 'min:1'],
            "{$prefix}process" => ['nullable', 'string', 'max:60'],
            "{$prefix}note_start" => ['nullable', 'string', 'max:2000'],
            "{$prefix}npbg_no" => ['nullable', 'string', 'max:40'],
            "{$prefix}finish_date" => ['nullable', 'date'],
            "{$prefix}ri_no" => ['nullable', 'string', 'max:40'],
            "{$prefix}note_end" => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function row(ManufacturingOrder $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'kind' => $o->kind, 'status' => $o->status,
            'product_name' => $o->product_name, 'location' => $o->location, 'date' => $o->date?->toDateString(),
            'completed_at' => $o->completed_at?->toDateString(),
            'vendor_name' => $o->relationLoaded('vendor') ? $o->vendor?->name : null,
            'subs_count' => $o->subs_count, 'created_at' => $o->created_at,
        ];
    }
}
