<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RiResource;
use App\Models\Item;
use App\Models\PurchaseOrderItem;
use App\Models\Ri;
use App\Models\Warehouse;
use App\Services\DocumentNumberService;
use App\Services\Export\DocumentPrintRenderer;
use App\Services\Inventory\StockLedgerService;
use App\Services\ItemRequestFulfillmentService;
use App\Services\PurchaseOrderService;
use App\Services\Tracking\UsedReturnSheets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * RI — Accurate APINV/APITMDET mirror. Mostly read-only (rows come from Sync
 * Accurate, App\Services\Accurate\AccurateSyncService) — but store() also
 * allows creating a row manually for goods-in that never went through
 * Accurate (see Ri model docblock for the accurate_* = null convention).
 * A manual RI of a master item adds to its stock (Accurate never sees it — StockwiseNet keeps it
 * through the Accurate sync) and Request Barang waiting for goods return to Gudang.
 */
class RiController extends Controller
{
    public function __construct(private DocumentNumberService $numbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Ri::query()->with(
            'poItem:id,purchase_order_id,accurate_ppb_id',
            'poItem.purchaseOrder:id,number,date',
            'poItem.accuratePpb:id,no_ppb,tgl_ppb',
        );

        // free text, word by word: every word must be found somewhere on this
        // line — no. RI, barang, vendor, pemeriksa, no. PPB/PO, keterangan, dll
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $query->where(fn ($q) => $q->where('no_ri', 'like', "%{$w}%")
                ->orWhereLoose('kode_barang', $w)
                ->orWhereLoose('deskripsi_barang', $w)
                ->orWhere('vendor', 'like', "%{$w}%")
                ->orWhere('pemeriksa', 'like', "%{$w}%")
                ->orWhere('no_surat_jalan', 'like', "%{$w}%")
                ->orWhere('no_ppb', 'like', "%{$w}%")
                ->orWhere('no_po', 'like', "%{$w}%")
                ->orWhere('no_seri', 'like', "%{$w}%")
                ->orWhere('divisi', 'like', "%{$w}%")
                ->orWhere('keterangan', 'like', "%{$w}%"));
        }

        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        // ?sumber=PO|BEKAS|MANUAL|ACCURATE
        $request->whenFilled('sumber', fn ($v) => $query->whereRaw(Ri::sourceSql().' = ?', [$v]));
        $request->whenFilled('vendor', fn ($v) => $query->where('vendor', $v));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ri', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ri', '<=', $v));

        $query->orderByDesc('tgl_ri')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => RiResource::collection($page->getCollection())->resolve(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Vendor options for the filter dropdown — distinct text values as Accurate/sync actually stored them (RI's `vendor` is a plain synced string, not every row resolves to a `vendors` master row — see Ri::vendorRecord()). */
    public function vendorOptions(): JsonResponse
    {
        $options = Ri::query()
            ->whereNotNull('vendor')
            ->where('vendor', '!=', '')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor');

        return response()->json(['data' => $options]);
    }

    /**
     * GET /api/ri/po-lines?search= — STOCKWISE PO lines (approved / sent / partly received) still waiting
     * for goods, for the "Dari PO" picker of a manual RI.
     */
    public function poLines(Request $request): JsonResponse
    {
        $q = PurchaseOrderItem::query()->with('purchaseOrder:id,number,vendor_id,status', 'purchaseOrder.vendor:id,name', 'vendor:id,name', 'item:id,code', 'unit:id,code')
            ->whereHas('purchaseOrder', fn ($p) => $p->whereNull('accurate_po_id')->whereIn('status', ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED']))
            ->whereColumn('qty_received', '<', 'qty');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($w) => $w->where('description_raw', 'like', "%{$s}%")
                ->orWhereHas('purchaseOrder', fn ($p) => $p->where('number', 'like', "%{$s}%"))
                ->orWhereHas('item', fn ($i) => $i->where('code', 'like', "%{$s}%")));
        }

        return response()->json(['data' => $q->orderByDesc('id')->limit(30)->get()->map(fn (PurchaseOrderItem $l) => [
            'id' => $l->id, 'po_id' => $l->purchase_order_id, 'po_number' => $l->purchaseOrder->number, 'vendor' => $l->vendorName(),
            'kode_barang' => $l->item?->code, 'deskripsi' => $l->description_raw, 'satuan' => $l->unit?->code,
            'qty' => (float) $l->qty, 'received' => (float) $l->qty_received, 'remaining' => round((float) $l->qty - (float) $l->qty_received, 2),
            'unit_price' => (float) $l->unit_price,
        ])]);
    }

    /**
     * GET /api/ri/po-orders?search= — STOCKWISE POs that can still receive goods (no. PO, vendor or one
     * of its items), each with ALL its lines (ordered / received / left), for "RI dari PO": the user
     * types how much of each line arrived (part of it is fine) and saves one RI for them.
     */
    public function poOrders(Request $request): JsonResponse
    {
        $q = \App\Models\PurchaseOrder::query()->with('vendor:id,name', 'items.vendor:id,name', 'items.item:id,code', 'items.unit:id,code', 'items.approvedPpb:id,no_ppb', 'items.accuratePpb:id,no_ppb')
            ->whereNull('accurate_po_id')->whereIn('status', ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED'])
            ->whereHas('items', fn ($i) => $i->whereColumn('qty_received', '<', 'qty'));
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $q->where(fn ($x) => $x->where('number', 'like', "%{$w}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$w}%"))
                ->orWhereHas('items.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%"))
                ->orWhereHas('items', fn ($i) => $i->where('description_raw', 'like', "%{$w}%")->orWhereHas('item', fn ($it) => $it->where('code', 'like', "%{$w}%"))));
        }

        return response()->json(['data' => $q->orderByDesc('date')->orderByDesc('id')->limit(20)->get()->map(fn ($po) => [
            'id' => $po->id, 'number' => $po->number, 'date' => $po->date?->toDateString(), 'status' => $po->status,
            // one PO number may hold items of several vendors
            'vendor' => $po->items->map(fn ($l) => $l->vendor?->name ?? $po->vendor?->name)->filter()->unique()->implode(', ') ?: null,
            'lines' => $po->items->sortBy('id')->values()->map(fn ($l) => [
                'id' => $l->id, 'kode_barang' => $l->item?->code, 'deskripsi' => $l->description_raw, 'satuan' => $l->unit?->code,
                'vendor' => $l->vendor?->name ?? $po->vendor?->name,
                'no_ppb' => $l->approvedPpb?->no_ppb ?? $l->accuratePpb?->no_ppb,
                'qty' => (float) $l->qty, 'received' => (float) $l->qty_received, 'remaining' => max(0, round((float) $l->qty - (float) $l->qty_received, 2)),
                'unit_price' => (float) $l->unit_price,
            ]),
        ])]);
    }

    /**
     * POST /api/ri/from-po — one RI for the goods of a STOCKWISE PO that arrived: per line how much came
     * (part of what is left is fine: the PO stays Partial until the rest comes with a next RI),
     * 1–3 photos of the goods and up to 3 documents (surat jalan / invoice, photo or PDF).
     */
    public function storeFromPo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'divisi' => ['required', 'in:NA,ATK,NV'],
            'no_surat_jalan' => ['nullable', 'string', 'max:60'],
            'pemeriksa' => ['nullable', 'string', 'max:150'],
            'tgl_ri' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'integer', 'distinct', 'exists:purchase_order_items,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.no_seri' => ['nullable', 'string'],
            // proof per item: 1 to 3 photos of each item received
            'lines.*.photos' => ['nullable', 'array', 'max:3'],
            'lines.*.photos.*' => ['required', 'string', 'max:12000000'],
            'documents' => ['nullable', 'array', 'max:3'],
            'documents.*.data' => ['required', 'string', 'max:15000000'],
            'documents.*.name' => ['nullable', 'string', 'max:150'],
            'allow_duplicate' => ['sometimes', 'boolean'],
        ], [
            'lines.*.photos.max' => 'Foto bukti maksimal 3 per barang.',
            'documents.max' => 'Dokumen maksimal 3.',
        ]);
        $lines = collect($data['lines'])->filter(fn ($l) => (float) ($l['qty'] ?? 0) > 0)->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Isi jumlah yang diterima minimal untuk satu barang.']);
        }
        $po = \App\Models\PurchaseOrder::with('vendor:id,name')->findOrFail($data['purchase_order_id']);
        $items = PurchaseOrderItem::with('vendor:id,name', 'item:id,code', 'unit:id,code', 'approvedPpb:id,no_ppb', 'accuratePpb:id,no_ppb')
            ->where('purchase_order_id', $po->id)->whereIn('id', $lines->pluck('purchase_order_item_id'))->get()->keyBy('id');
        if ($items->count() !== $lines->count()) {
            throw ValidationException::withMessages(['lines' => 'Ada barang yang bukan dari PO ini.']);
        }
        // every item received has its own proof
        foreach ($lines as $l) {
            if (empty($l['photos'])) {
                throw ValidationException::withMessages(['photos' => 'Upload minimal 1 foto bukti untuk '.$items[$l['purchase_order_item_id']]->description_raw.'.']);
            }
        }
        $allow = (bool) ($data['allow_duplicate'] ?? false);
        $user = $request->user();

        // one PO received at a time (two admins, one delivery)
        $first = \App\Support\DuplicateGuard::locked('ri-po', (string) $po->id, fn () => DB::transaction(function () use ($data, $lines, $items, $po, $user, $allow) {
            $no = $this->numbers->next('RI', 'MANUAL');
            $warehouseId = Warehouse::query()->orderBy('id')->value('id');
            $first = null;
            foreach ($lines as $l) {
                $line = $items[$l['purchase_order_item_id']];
                $qty = round((float) $l['qty'], 2);
                $row = [
                    'divisi' => $data['divisi'], 'sumber' => 'PO', 'vendor' => $line->vendor?->name ?? $po->vendor?->name, 'no_po' => $po->number,
                    'no_ppb' => $line->approvedPpb?->no_ppb ?? $line->accuratePpb?->no_ppb, 'no_surat_jalan' => $data['no_surat_jalan'] ?? null,
                    'kode_barang' => $line->item?->code, 'deskripsi_barang' => $line->description_raw, 'kuantitas' => $qty, 'satuan' => $line->unit?->code,
                    'harga_satuan' => (float) $line->unit_price, 'no_seri' => $l['no_seri'] ?? null, 'pemeriksa' => $data['pemeriksa'] ?? null,
                    'tgl_ri' => $data['tgl_ri'] ?? now()->toDateString(), 'keterangan' => $data['keterangan'] ?? null,
                ];
                [$found, $hard] = \App\Support\DuplicateGuard::ri($row);
                \App\Support\DuplicateGuard::refuse($found, $allow, $hard);
                app(PurchaseOrderService::class)->receive($line, $qty);
                $ri = Ri::create($row + ['no_ri' => $no, 'accurate_po_item_id' => $line->id, 'created_by' => $user->id]);
                $first ??= $ri;
                // the proof of this item, on its own RI line
                foreach (array_values($l['photos']) as $i => $photo) {
                    app(\App\Services\Tracking\TrackingPhotoService::class)->putDataUrl('ri', $ri->id, 'foto'.($i + 1), $photo, 'bukti-'.($line->item?->code ?? 'barang').'-'.($i + 1), $user->id);
                }
                $item = $line->item_id ? Item::find($line->item_id) : (filled($ri->kode_barang) ? Item::where('code', $ri->kode_barang)->first() : null);
                if ($item && $warehouseId) {
                    app(StockLedgerService::class)->record(['type' => 'RECEIVING', 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
                        'qty' => $qty, 'reference' => $ri, 'note' => "RI {$no} · PO {$po->number}", 'created_by' => $user->id]);
                }
            }
            $files = app(\App\Services\Tracking\TrackingPhotoService::class);
            foreach (array_values($data['documents'] ?? []) as $i => $doc) {
                $files->putDataUrl('ri_doc', $first->id, 'doc'.($i + 1), $doc['data'], $doc['name'] ?? 'dokumen-ri-'.($i + 1), $user->id);
            }

            return $first;
        }));
        app(ItemRequestFulfillmentService::class)->refreshWaiting();

        return response()->json(['data' => new RiResource($first)], 201);
    }

    /**
     * GET /api/ri/used-return-lines?search= — used goods still to come back (Tracking Pengembalian Bekas,
     * "Spare Part Lain", no RI back yet), for the "Pengembalian Bekas" picker of a manual RI.
     */
    public function usedReturnLines(Request $request): JsonResponse
    {
        $q = DB::table(UsedReturnSheets::OTHER.' as u')->leftJoin('npbg as n', 'n.id', '=', 'u.npbg_id')
            ->where(fn ($w) => $w->whereNull('u.ri_no')->orWhere('u.ri_no', ''))
            ->whereNotNull('u.npbg_no')->where('u.npbg_no', '!=', '');
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $q->where(fn ($x) => $x->where('u.npbg_no', 'like', "%{$w}%")->orWhere('u.description', 'like', "%{$w}%")
                ->orWhere('n.kode_barang', 'like', "%{$w}%")->orWhere('n.pelanggan', 'like', "%{$w}%")->orWhere('n.peminta', 'like', "%{$w}%"));
        }

        return response()->json(['data' => $q->orderByDesc('u.npbg_date')->orderByDesc('u.id')->limit(30)
            ->get(['u.id', 'u.npbg_no', 'u.npbg_date', 'u.description', 'u.qty', 'u.unit', 'u.item_no', 'n.kode_barang', 'n.pelanggan', 'n.peminta', 'n.divisi'])
            ->map(fn ($r) => [
                'id' => $r->id, 'npbg_no' => $r->npbg_no, 'npbg_date' => $r->npbg_date, 'deskripsi' => $r->description,
                'qty' => $r->qty !== null ? (float) $r->qty : null, 'satuan' => $r->unit, 'item_no' => $r->item_no,
                'kode_barang' => $r->kode_barang, 'pelanggan' => $r->pelanggan, 'peminta' => $r->peminta, 'divisi' => $r->divisi,
            ])]);
    }

    /**
     * POST /api/ri — manual RI, for goods-in that never went through Accurate.
     * sumber: PO (a STOCKWISE PO line), BEKAS (used goods back: its Tracking Pengembalian Bekas row gets
     * this RI and its first photo as "Foto Terima" — CLEAR; used goods do not add to the stock) or
     * MANUAL (anything else, tied to nothing).
     * Numbered distinctly ("RI/MANUAL/26/X/001") so it never collides with a
     * real Accurate invoice number; accurate_apinvoice_id/accurate_seq stay
     * null, the same "is the accurate id null?" marker used elsewhere.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'divisi' => ['required', 'in:NA,ATK,NV'],
            'vendor' => ['nullable', 'string', 'max:200'],
            'no_po' => ['nullable', 'string', 'max:60'],
            'no_surat_jalan' => ['nullable', 'string', 'max:60'],
            'kode_barang' => ['nullable', 'string', 'max:60'],
            'deskripsi_barang' => ['required', 'string', 'max:400'],
            'kuantitas' => ['required', 'numeric', 'min:0.01'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'harga_satuan' => ['nullable', 'numeric', 'min:0'],
            'no_seri' => ['nullable', 'string'],
            'pemeriksa' => ['nullable', 'string', 'max:150'],
            'tgl_ri' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string'],
            // goods of a STOCKWISE PO line (maybe only part of it): the PO line counts them as received
            'purchase_order_item_id' => ['nullable', 'integer', 'exists:purchase_order_items,id', 'required_if:sumber,PO'],
            'sumber' => ['nullable', 'in:'.implode(',', Ri::SOURCES)],
            // used goods back: the Tracking Pengembalian Bekas row ("Spare Part Lain")
            'used_return_row_id' => ['nullable', 'integer', 'exists:'.UsedReturnSheets::OTHER.',id', 'required_if:sumber,BEKAS'],
            // the user confirmed it is not the same RI as a recent look-alike (DuplicateGuard)
            'allow_duplicate' => ['sometimes', 'boolean'],
            // proof of the goods received: 1 to 3 photos (JPEG / PNG / WEBP data URLs)
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['required', 'string', 'max:12000000'],
        ], [
            'photos.required' => 'Upload minimal 1 foto bukti penerimaan barang.',
            'photos.min' => 'Upload minimal 1 foto bukti penerimaan barang.',
            'photos.max' => 'Foto bukti maksimal 3.',
            'purchase_order_item_id.required_if' => 'Pilih barang PO yang diterima.',
            'used_return_row_id.required_if' => 'Pilih barang bekas yang dikembalikan.',
        ]);
        $allowDuplicate = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);
        $usedRowId = $data['used_return_row_id'] ?? null;
        $data['sumber'] ??= ! empty($data['purchase_order_item_id']) ? 'PO' : ($usedRowId ? 'BEKAS' : 'MANUAL');
        // one source only: a manual RI is tied to nothing
        if ($data['sumber'] !== 'PO') {
            $data['purchase_order_item_id'] = null;
        }
        if ($data['sumber'] !== 'BEKAS') {
            $usedRowId = null;
        }
        unset($data['used_return_row_id']);
        $photos = array_values($data['photos']);
        unset($data['photos']);

        $data['tgl_ri'] ??= now()->toDateString();
        $poLineId = $data['purchase_order_item_id'] ?? null;
        unset($data['purchase_order_item_id']);

        // never the same goods received twice (two admins, one delivery): checked and saved under one lock per item
        $ri = \App\Support\DuplicateGuard::locked('ri', $data['kode_barang'] ?? $data['deskripsi_barang'], fn () => DB::transaction(function () use ($data, $request, $poLineId, $photos, $usedRowId, $allowDuplicate) {
            if (! $usedRowId) {
                [$found, $hard] = \App\Support\DuplicateGuard::ri($data);
                \App\Support\DuplicateGuard::refuse($found, $allowDuplicate, $hard);
            }
            $used = null;
            if ($usedRowId) {
                $used = DB::table(UsedReturnSheets::OTHER)->where('id', $usedRowId)->lockForUpdate()->first();
                if (trim((string) $used->ri_no) !== '') {
                    throw ValidationException::withMessages(['used_return_row_id' => "Barang bekas ini sudah dikembalikan dengan RI {$used->ri_no}."]);
                }
                $data['keterangan'] = trim(($data['keterangan'] ?? '') ?: "Pengembalian bekas NPBG {$used->npbg_no}");
                $data['satuan'] ??= $used->unit;
                $data['used_return_row_id'] = $used->id;
            }
            if ($poLineId) {
                $line = PurchaseOrderItem::with('purchaseOrder.vendor:id,name', 'vendor:id,name', 'item:id,code', 'unit:id,code')->findOrFail($poLineId);
                app(PurchaseOrderService::class)->receive($line, (float) $data['kuantitas']);
                $data = array_merge($data, array_filter([
                    'no_po' => $line->purchaseOrder->number, 'vendor' => $data['vendor'] ?? $line->vendorName(),
                    'kode_barang' => $line->item?->code ?? ($data['kode_barang'] ?? null), 'satuan' => $data['satuan'] ?? $line->unit?->code,
                    'harga_satuan' => $data['harga_satuan'] ?? (float) $line->unit_price,
                ], fn ($v) => $v !== null)) + ['accurate_po_item_id' => $line->id]; // same PO-line link Accurate's RI use
            }
            $ri = Ri::create($data + [
                'no_ri' => $this->numbers->next('RI', 'MANUAL'),
                'created_by' => $request->user()->id,
            ]);
            // used goods do not add to the stock of the (new) item
            $item = $used === null && filled($ri->kode_barang) ? Item::where('code', $ri->kode_barang)->first() : null;
            $warehouseId = Warehouse::query()->orderBy('id')->value('id');
            if ($item && $warehouseId) {
                app(StockLedgerService::class)->record(['type' => 'RECEIVING', 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
                    'qty' => (float) $ri->kuantitas, 'reference' => $ri, 'note' => "RI {$ri->no_ri}", 'created_by' => $request->user()->id]);
            }
            $photoService = app(\App\Services\Tracking\TrackingPhotoService::class);
            foreach ($photos as $i => $photo) {
                $photoService->putDataUrl('ri', $ri->id, 'foto'.($i + 1), $photo, 'bukti-ri-'.($i + 1), $request->user()->id);
            }
            if ($used) {
                // the tracking row: back with this RI, its first photo is the "Foto Terima" → CLEAR
                DB::table(UsedReturnSheets::OTHER)->where('id', $used->id)->update(['ri_no' => $ri->no_ri, 'return_item_no' => 1,
                    'in_file_name' => UsedReturnSheets::fileName($ri->no_ri, 1), 'updated_at' => now()]);
                $photoService->putDataUrl(UsedReturnSheets::PHOTO_ENTITY, $used->id, 'in', $photos[0], UsedReturnSheets::fileName($ri->no_ri, 1), $request->user()->id);
                UsedReturnSheets::recomputeOther($used->id);
            }

            return $ri;
        }));
        app(ItemRequestFulfillmentService::class)->refreshWaiting();

        return response()->json(['data' => new RiResource($ri)], 201);
    }

    /**
     * GET /api/ri/{ri}/print — one printable RI document, styled after the
     * real Accurate template (Template\RI.frf). Groups every line sharing the
     * same no_ri into one item table, same as NpbgController::print().
     */
    /**
     * GET /api/ri/{ri}/print — one printable RI document, positioned to match
     * the real Accurate template (Template\RI.frf, page 210×147mm) — coordinates
     * read out of that file's binary, see NpbgController::print(). No "Pemeriksa"
     * slot exists on this particular template (only Vendor/No. RI/Tgl. RI/No.
     * Surat Jalan/Tgl. Kirim/Pengirim/FOB in the header, plus No. PPB/No. PO
     * per item line) — pemeriksa isn't printed here, same as the real form.
     */
    public function print(Request $request, Ri $ri, DocumentPrintRenderer $renderer): Response
    {
        $html = $renderer->render(...$this->printArgs($ri, $request));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** GET …/print-pdf — the same document as a real PDF (data only), printed from a desktop PDF reader; see DocumentPdfWriter. */
    public function printPdf(Request $request, Ri $ri, DocumentPrintRenderer $renderer): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($ri->no_ri ?? 'dokumen')).'.pdf';

        return response($renderer->pdf(...$this->printArgs($ri, $request)))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    private function printArgs(Ri $ri, Request $request): array
    {
        $lines = $ri->no_ri ? Ri::where('no_ri', $ri->no_ri)->orderBy('id')->get() : collect([$ri]);
        $first = $lines->first();
        $fmtDate = fn ($d) => $d?->format('d/m/Y');

        return [
            'pageWidthMm' => 210,
            'pageHeightMm' => 147,
            'offsetXMm' => (float) $request->input('offset_x', 0),
            'offsetYMm' => (float) $request->input('offset_y', 0),
            'autoPageSize' => $request->string('page_size', 'exact')->value() === 'auto',
            'texts' => [
                ['x' => 28.6, 'y' => 6.3, 'w' => 78.3, 'text' => 'PT SURYA INTI GAS', 'size' => 9, 'bold' => true, 'static' => true],
                ['x' => 121.7, 'y' => 5.3, 'w' => 73, 'h' => 16.9, 'text' => "PENERIMAAN BARANG\n(RI)", 'size' => 9, 'bold' => true, 'align' => 'center', 'static' => true],
                ['x' => 7.4, 'y' => 24.3, 'w' => 11.6, 'text' => 'Vendor', 'bold' => true, 'static' => true],
                ['x' => 23.3, 'y' => 24.3, 'w' => 96.3, 'text' => $first->vendor],
                ['x' => 121.7, 'y' => 23.3, 'w' => 9.5, 'text' => 'No. RI', 'bold' => true, 'static' => true],
                ['x' => 121.7, 'y' => 26.5, 'w' => 37, 'text' => $first->no_ri],
                ['x' => 158.8, 'y' => 23.3, 'w' => 10.6, 'text' => 'Tgl. RI', 'bold' => true, 'static' => true],
                ['x' => 158.8, 'y' => 26.5, 'w' => 36, 'text' => $fmtDate($first->tgl_ri)],
                ['x' => 121.7, 'y' => 30.7, 'w' => 22.2, 'text' => 'No. Surat Jalan', 'bold' => true, 'static' => true],
                ['x' => 122.8, 'y' => 33.9, 'w' => 36, 'text' => $first->no_surat_jalan],
                ['x' => 158.8, 'y' => 30.7, 'w' => 14.8, 'text' => 'Tgl. Kirim', 'bold' => true, 'static' => true],
                ['x' => 158.8, 'y' => 33.9, 'w' => 36, 'text' => $fmtDate($first->shipdate)],
                ['x' => 121.7, 'y' => 38.1, 'w' => 14.8, 'text' => 'Pengirim', 'bold' => true, 'static' => true],
                ['x' => 121.7, 'y' => 42.3, 'w' => 37, 'text' => $first->pengirim],
                ['x' => 158.8, 'y' => 38.1, 'w' => 7.4, 'text' => 'FOB', 'bold' => true, 'static' => true],
                ['x' => 158.8, 'y' => 41.3, 'w' => 36, 'text' => $first->fob],
                ['x' => 6.3, 'y' => 69.3, 'w' => 101.6, 'text' => 'Keterangan:', 'bold' => true, 'static' => true],
                ['x' => 6.3, 'y' => 73.2, 'w' => 101.6, 'h' => 13, 'text' => $first->keterangan],
            ],
            'table' => [
                'y' => 47.1,
                'rowHeight' => 6.9,
                'columns' => [
                    ['x' => 5.3, 'w' => 8.5, 'header' => 'No.', 'align' => 'center'],
                    ['x' => 13.8, 'w' => 21.2, 'header' => 'Kode Barang'],
                    ['x' => 34.9, 'w' => 56.1, 'header' => 'Deskripsi Barang'],
                    ['x' => 91.0, 'w' => 9.5, 'header' => 'Kts', 'align' => 'right'],
                    ['x' => 100.5, 'w' => 12.7, 'header' => 'Satuan'],
                    ['x' => 113.2, 'w' => 33.9, 'header' => 'No. Seri'],
                    ['x' => 147.1, 'w' => 23.3, 'header' => 'No. PPB'],
                    ['x' => 170.4, 'w' => 24.3, 'header' => 'No. PO'],
                ],
            ],
            'tableRows' => $lines->values()->map(fn ($l, $i) => [
                $i + 1, $l->kode_barang, $l->deskripsi_barang, $l->kuantitas, $l->satuan, $l->no_seri, $l->no_ppb, $l->no_po,
            ])->all(),
            'signatures' => [
                'y' => 68.3,
                'lineY' => 83.1,
                'roleY' => 84.1,
                'columns' => [
                    ['x' => 110.1, 'w' => 21.2, 'action' => 'Diterima Oleh', 'role' => 'Nama:'],
                    ['x' => 131.2, 'w' => 21.2, 'action' => 'Dibuat Oleh', 'role' => 'Adm. Gudang'],
                    ['x' => 152.4, 'w' => 21.2, 'action' => 'Diperiksa Oleh', 'role' => 'Manager'],
                    ['x' => 173.6, 'w' => 21.2, 'action' => 'Disetujui Oleh', 'role' => 'Direktur'],
                ],
            ],
        ];
    }

    /** GET /api/ri/{ri}/photo/{slot} — a proof photo (foto1..3) or document (doc1..3) of a manual RI. */
    public function photo(Ri $ri, string $slot): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $doc = str_starts_with($slot, 'doc');
        $p = (! $doc ? \App\Models\TrackingPhoto::where(['entity' => 'ri', 'entity_id' => $ri->id, 'slot' => $slot])->first() : null)
            ?? \App\Models\TrackingPhoto::where(['entity' => $doc ? 'ri_doc' : 'ri', 'entity_id' => $ri->fileOwnerId(), 'slot' => $slot])->first();
        abort_unless($p && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($p->path, null, ['Content-Type' => $p->mime ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function show(Ri $ri): RiResource
    {
        $ri->load(
            'poItem:id,purchase_order_id,accurate_ppb_id',
            'poItem.purchaseOrder:id,number,date',
            'poItem.accuratePpb:id,no_ppb,tgl_ppb',
        );

        return new RiResource($ri);
    }
}
