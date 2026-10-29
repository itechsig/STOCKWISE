<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Export\DocumentPrintRenderer;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    /**
     * One row per item line (like PPB/RI), not one row per PO with every
     * line's code/description/qty joined into a single crowded cell — a
     * multi-item PO now shows as several rows sharing the same Nomor PO,
     * same as Accurate's own PODET is one row per line.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->with(
                'purchaseOrder:id,number,date,vendor_id,status,notes,accurate_po_id',
                'purchaseOrder.vendor:id,name',
                'vendor:id,name',
                'item:id,code,description',
                'unit:id,code',
                'accuratePpb:id,no_ppb,tgl_ppb,divisi',
                'approvedPpb:id,no_ppb,tgl_ppb,divisi',
                'riLines:id,accurate_po_item_id,no_ri,kuantitas,tgl_ri',
            )
            ->select('purchase_order_items.*');

        // the Status column of this list is the line's own status (PENDING / PARTIAL_RECEIVED / RECEIVED / CLOSED)
        $request->whenFilled('status', fn ($v) => $query->where('purchase_order_items.line_status', strtoupper((string) $v)));
        // the line's own vendor, else the PO's (one PO number may hold items of several vendors)
        $request->whenFilled('vendor_id', fn ($v) => $query->where(fn ($q) => $q->where('purchase_order_items.vendor_id', $v)
            ->orWhere(fn ($x) => $x->whereNull('purchase_order_items.vendor_id')->where('purchase_orders.vendor_id', $v))));
        $request->whenFilled('divisi', fn ($v) => $query->whereHas('accuratePpb', fn ($p) => $p->where('divisi', $v)));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('purchase_orders.date', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('purchase_orders.date', '<=', $v));
        $request->whenFilled('source', fn ($v) => $v === 'accurate' ? $query->whereNotNull('purchase_orders.accurate_po_id') : ($v === 'internal' ? $query->whereNull('purchase_orders.accurate_po_id') : null));
        // free text, word by word: every word must be found somewhere on this
        // line — no. PO, vendor, barang (kode / nama), no. PPB, no. RI, divisi, or notes
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $query->where(fn ($q) => $q->where('purchase_orders.number', 'like', "%{$w}%")
                ->orWhere('purchase_orders.notes', 'like', "%{$w}%")
                ->orWhereHas('purchaseOrder.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%")->orWhere('code', 'like', "%{$w}%"))
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$w}%")->orWhere('code', 'like', "%{$w}%"))
                ->orWhereLoose('purchase_order_items.description_raw', $w)
                ->orWhereHas('item', fn ($it) => $it->where('code', 'like', "%{$w}%")->orWhereLoose('description', $w))
                ->orWhereHas('accuratePpb', fn ($p) => $p->where('no_ppb', 'like', "%{$w}%")->orWhere('divisi', 'like', "%{$w}%"))
                ->orWhereHas('approvedPpb', fn ($p) => $p->where('no_ppb', 'like', "%{$w}%"))
                ->orWhereHas('riLines', fn ($r) => $r->where('no_ri', 'like', "%{$w}%")));
        }
        $query->orderByDesc('purchase_orders.created_at')->orderBy('purchase_order_items.accurate_seq');
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100))->withQueryString();
        // was this line's PPB approved before PO? (PO_TANPA_APPROVAL = Accurate made the PO first — shown in red)
        $approval = \App\Services\PpbApprovalService::statusFor(collect($page->items())->map(fn ($l) => ($l->accuratePpb ?? $l->approvedPpb)?->no_ppb));

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseOrderItem $l) => $this->lineRow($l) + ['ppb_approval' => $approval[($l->accuratePpb ?? $l->approvedPpb)?->no_ppb] ?? null]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Divisi options for the filter dropdown — same real, evolving list as PPB's (PO's divisi is inherited from its linked PPB), minus "NA"/"ATK" which are a numbering code, not a division (see Ppb::ppbDivisi() on the frontend). */
    public function divisiOptions(): JsonResponse
    {
        $options = \App\Models\Ppb::query()
            ->whereNotNull('divisi')
            ->whereNotIn('divisi', ['NA', 'ATK'])
            ->distinct()
            ->orderBy('divisi')
            ->pluck('divisi');

        return response()->json(['data' => $options]);
    }

    /**
     * POST /api/purchase-orders — manual PO (DRAFT), for purchases that never
     * went through Accurate. Each line either picks a real Master Barang item
     * (item_id) or, when "Barang Baru" is checked on the frontend, is pure
     * free text (description_raw only, item_id null) — same convention as
     * NPBG/PPB manual entries, just via a real FK here instead of a free-text
     * kode_barang column.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.description_raw' => ['required', 'string', 'max:400'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            // the user confirmed it is not the same PO as a recent look-alike (DuplicateGuard)
            'allow_duplicate' => ['sometimes', 'boolean'],
        ]);
        $allowDuplicate = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);

        // never the same PO twice (two admins, one need)
        $po = \App\Support\DuplicateGuard::locked('po', 'vendor-'.$data['vendor_id'], function () use ($request, $data, $allowDuplicate) {
            \App\Support\DuplicateGuard::refuse(\App\Support\DuplicateGuard::po($data), $allowDuplicate);

            return $this->service->create($request->user(), $data);
        });

        return $this->show($po->fresh());
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(
            'vendor', 'items.vendor:id,name', 'items.item:id,code', 'items.unit:id,code',
            'items.accuratePpb:id,no_ppb', 'items.approvedPpb:id,no_ppb', 'items.riLines:id,accurate_po_item_id,no_ri,kuantitas,tgl_ri',
        );

        $user = request()->user();
        $approval = PurchaseOrderService::approvalStatus($purchaseOrder);

        return response()->json(['data' => $this->row($purchaseOrder) + [
            // STOCKWISE PO: Direktur approval (MENUNGGU_DIREKTUR / DIKEMBALIKAN / DISETUJUI) and its log
            'approval_status' => $approval,
            'approval_log' => $purchaseOrder->accurate_po_id === null ? PurchaseOrderService::approvalLog($purchaseOrder) : [],
            'from_ppb_approval' => $purchaseOrder->from_ppb_approval,
            'can_complete' => $user && ($user->hasPermission('po.create') || $user->hasPermission('po.update')) && PurchaseOrderService::editable($purchaseOrder),
            'can_approve' => $user && $user->hasPermission(PurchaseOrderService::DIRECTOR) && $purchaseOrder->status === 'DRAFT' && $approval === 'MENUNGGU_DIREKTUR',
            // proof of purchase: uploaded once the Direktur approved (→ "Sudah dibeli")
            'proof' => ($proof = \App\Models\TrackingPhoto::where(['entity' => 'purchase_order', 'entity_id' => $purchaseOrder->id, 'slot' => 'bukti'])->first())
                ? ['file_name' => $proof->file_name ?? null, 'mime' => $proof->mime, 'at' => $proof->created_at?->copy()->timezone(config('app.timezone'))->toIso8601String()] : null,
            'can_upload_proof' => $user && $purchaseOrder->accurate_po_id === null && ($user->hasPermission('po.send') || $user->hasPermission('po.create'))
                && in_array($purchaseOrder->status, ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED', 'RECEIVED'], true),
            'vendor' => $purchaseOrder->vendor?->only('id', 'name'),
            // every vendor of its lines (one PO number may hold items of several vendors)
            'vendors' => $purchaseOrder->items->map(fn ($l) => $l->vendor ?? $purchaseOrder->vendor)->filter()->unique('id')->values()->map->only('id', 'name'),
            'expected_date' => $purchaseOrder->expected_date?->toDateString(),
            'notes' => $purchaseOrder->notes,
            'items' => $purchaseOrder->items->map(fn ($l) => [
                'id' => $l->id, 'item_id' => $l->item_id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
                'vendor_id' => $l->vendor_id ?? $purchaseOrder->vendor_id, 'vendor_name' => $l->vendor?->name ?? $purchaseOrder->vendor?->name,
                'qty' => $l->qty, 'unit' => $l->unit?->code, 'unit_price' => $l->unit_price,
                'line_total' => $l->line_total, 'qty_received' => $l->qty_received, 'line_status' => $l->line_status,
                // Accurate's own PODET.REQID/REQSEQ chain — which PPB (REQUISITIONDET) this line was raised from, and which RI lines received against it.
                'source_ppb' => ($src = $l->accuratePpb ?? $l->approvedPpb) ? ['id' => $src->id, 'no_ppb' => $src->no_ppb] : null,
                'received_via' => $l->riLines->map(fn ($r) => ['id' => $r->id, 'no_ri' => $r->no_ri, 'kuantitas' => $r->kuantitas, 'tgl_ri' => $r->tgl_ri?->toDateString()]),
            ]),
        ]]);
    }

    /**
     * GET /api/purchase-orders/{purchaseOrder}/print — one printable PO,
     * styled after the real Accurate template (Template\PO.frf). Unlike
     * NPBG/PPB/RI, a PO's lines are already a proper relation (items()),
     * nothing to group by number here.
     */
    /**
     * GET /api/purchase-orders/{purchaseOrder}/print — one printable PO,
     * positioned to match the real Accurate template (Template\PO.frf, A4
     * portrait 210×297mm) — coordinates read out of that file's binary, see
     * NpbgController::print(). Fields the template has but this app doesn't
     * track (Ship To, Terms of Payment, Ship Via, Inclusive Tax, Discount,
     * Freight, amount-in-words) are left blank rather than invented.
     */
    public function print(Request $request, PurchaseOrder $purchaseOrder, DocumentPrintRenderer $renderer): Response
    {
        $html = $renderer->render(...$this->printArgs($purchaseOrder, $request));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** GET …/print-pdf — the same document as a real PDF (data only), printed from a desktop PDF reader; see DocumentPdfWriter. */
    public function printPdf(Request $request, PurchaseOrder $purchaseOrder, DocumentPrintRenderer $renderer): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($purchaseOrder->number ?? 'dokumen')).'.pdf';

        return response($renderer->pdf(...$this->printArgs($purchaseOrder, $request)))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    private function printArgs(PurchaseOrder $purchaseOrder, Request $request): array
    {
        $purchaseOrder->load('vendor', 'items.vendor:id,name,address', 'items.item:id,code', 'items.unit:id,code');
        $vendors = $purchaseOrder->items->map(fn ($l) => $l->vendor ?? $purchaseOrder->vendor)->filter()->unique('id')->values();
        $fmtDate = fn ($d) => $d?->format('d/m/Y');
        $fmtMoney = fn ($v) => $v !== null && (float) $v !== 0.0 ? number_format((float) $v, 0, ',', '.') : null;

        return [
            'pageWidthMm' => 210,
            'pageHeightMm' => 297,
            'offsetXMm' => (float) $request->input('offset_x', 0),
            'offsetYMm' => (float) $request->input('offset_y', 0),
            'autoPageSize' => $request->string('page_size', 'exact')->value() === 'auto',
            'logoBox' => ['x' => 6.6, 'y' => 2.4, 'w' => 16.9, 'h' => 17.2],
            'texts' => [
                ['x' => 25.9, 'y' => 2.9, 'w' => 90.5, 'text' => 'PT SURYA INTI GAS', 'size' => 9, 'bold' => true, 'static' => true],
                ['x' => 120.7, 'y' => 2.6, 'w' => 73.6, 'h' => 6.3, 'text' => 'PURCHASE ORDER', 'size' => 10, 'bold' => true, 'align' => 'center', 'static' => true],
                ['x' => 6.3, 'y' => 19.3, 'w' => 10.6, 'text' => 'Supplier', 'bold' => true, 'static' => true],
                ['x' => 25.9, 'y' => 19.3, 'w' => 91.0, 'text' => $vendors->count() > 1 ? $vendors->pluck('name')->implode(', ') : $purchaseOrder->vendor?->name],
                ['x' => 25.9, 'y' => 23.3, 'w' => 91.0, 'h' => 8.2, 'text' => $vendors->count() > 1 ? null : $purchaseOrder->vendor?->address],
                ['x' => 124.4, 'y' => 10.8, 'w' => 26.5, 'text' => 'PO Date', 'bold' => true, 'static' => true],
                ['x' => 155.3, 'y' => 10.6, 'w' => 34.9, 'text' => $fmtDate($purchaseOrder->date)],
                ['x' => 124.4, 'y' => 16.1, 'w' => 26.5, 'text' => 'PO Number', 'bold' => true, 'static' => true],
                ['x' => 155.3, 'y' => 16.1, 'w' => 34.9, 'text' => $purchaseOrder->number],
                ['x' => 124.1, 'y' => 22.0, 'w' => 26.5, 'text' => 'Expected Date', 'bold' => true, 'static' => true],
                ['x' => 155.3, 'y' => 22.0, 'w' => 34.9, 'text' => $fmtDate($purchaseOrder->expected_date)],
                ['x' => 9.5, 'y' => 73.8, 'w' => 15.3, 'text' => 'Description', 'bold' => true, 'static' => true],
                ['x' => 6.6, 'y' => 77.8, 'w' => 123.3, 'h' => 15.6, 'text' => $purchaseOrder->notes],
                ['x' => 133.3, 'y' => 66.1, 'w' => 26.5, 'text' => 'Sub Total :', 'bold' => true, 'static' => true],
                ['x' => 161.9, 'y' => 66.1, 'w' => 31.8, 'text' => $fmtMoney($purchaseOrder->subtotal), 'align' => 'right'],
                ['x' => 133.3, 'y' => 75.4, 'w' => 26.2, 'text' => 'PPN :', 'bold' => true, 'static' => true],
                ['x' => 162.5, 'y' => 75.4, 'w' => 30.7, 'text' => $fmtMoney($purchaseOrder->tax), 'align' => 'right'],
                ['x' => 133.3, 'y' => 89.2, 'w' => 26.5, 'text' => 'Total Order :', 'bold' => true, 'static' => true],
                ['x' => 160.9, 'y' => 89.2, 'w' => 32.8, 'text' => $fmtMoney($purchaseOrder->total), 'align' => 'right', 'size' => 9, 'bold' => true],
            ],
            'table' => [
                'y' => 43.4,
                'rowHeight' => 7.1,
                'columns' => [
                    ['x' => 5.0, 'w' => 9.8, 'header' => 'No.', 'align' => 'center'],
                    ['x' => 41.5, 'w' => 15.6, 'header' => 'Item'],
                    ['x' => 57.1, 'w' => 71.4, 'header' => 'Description'],
                    ['x' => 128.6, 'w' => 12.2, 'header' => 'Qty', 'align' => 'right'],
                    ['x' => 140.8, 'w' => 13.8, 'header' => 'Satuan'],
                    ['x' => 154.5, 'w' => 18.5, 'header' => 'Unit Price', 'align' => 'right'],
                    ['x' => 173.0, 'w' => 22.2, 'header' => 'Amount', 'align' => 'right'],
                ],
            ],
            'tableRows' => $purchaseOrder->items->values()->map(fn ($l, $i) => [
                $i + 1, $l->item?->code, $l->description_raw, $l->qty, $l->unit?->code,
                $fmtMoney($l->unit_price), $fmtMoney($l->line_total),
            ])->all(),
            'signatures' => [
                'y' => 96.0,
                'lineY' => 110,
                'roleY' => 110.5,
                'columns' => [
                    ['x' => 12.4, 'w' => 29.6, 'action' => 'Supplier By', 'role' => ''],
                    ['x' => 57.9, 'w' => 28.6, 'action' => 'Prepared By', 'role' => ''],
                    ['x' => 114.0, 'w' => 28.6, 'action' => 'Approved By', 'role' => ''],
                    ['x' => 157.2, 'w' => 28.6, 'action' => 'Approved By', 'role' => ''],
                ],
            ],
        ];
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->show($this->service->approve($purchaseOrder, $request->user()));
    }

    /** PUT /api/purchase-orders/{id}/complete — vendor, prices (and quantities) of a STOCKWISE PO, then to the Direktur. */
    public function complete(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            // one PO = one vendor
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->show($this->service->complete($purchaseOrder, $request->user(), $data));
    }

    /** POST /api/purchase-orders/{id}/reject {note} — the Direktur sends it back to purchasing. */
    public function reject(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->show($this->service->reject($purchaseOrder, $request->user(), $data['note']));
    }

    /** POST /api/purchase-orders/{id}/proof (multipart: file) — the proof of purchase; the PO becomes "Sudah dibeli". */
    public function uploadProof(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp,pdf']]);
        $file = $data['file'];
        $this->service->uploadProof($purchaseOrder, $request->user(), (string) file_get_contents($file->getRealPath()),
            strtolower($file->getClientOriginalExtension() ?: $file->extension()), $file->getClientOriginalName());

        return $this->show($purchaseOrder->refresh());
    }

    /** GET /api/purchase-orders/{id}/proof — the proof of purchase file. */
    public function proof(PurchaseOrder $purchaseOrder): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $photo = \App\Models\TrackingPhoto::where(['entity' => 'purchase_order', 'entity_id' => $purchaseOrder->id, 'slot' => 'bukti'])->first();
        abort_unless($photo && \Illuminate\Support\Facades\Storage::disk('local')->exists($photo->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($photo->path, $photo->file_name ?? null, ['Content-Type' => $photo->mime ?? 'application/octet-stream', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function send(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->show($this->service->send($purchaseOrder));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->show($this->service->cancel($purchaseOrder, $data['reason']));
    }

    /** Comma-join of a distinct, non-empty collection — a PO with several lines can span several PPB/RI documents. */
    private function joinDistinct(\Illuminate\Support\Collection $values): ?string
    {
        $values = $values->filter()->unique()->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }

    /** index()'s per-line row — the PO's own header fields (number/date/vendor/notes) repeat on every one of its lines, same as how PPB repeats its header on every REQUISITIONDET row. */
    private function lineRow(PurchaseOrderItem $l): array
    {
        $po = $l->purchaseOrder;
        // Accurate's PPB->PO chain, or — on a "Menunggu PO" line — the approved PPB it was made from
        $ppb = $l->accuratePpb ?? ($l->relationLoaded('approvedPpb') ? $l->approvedPpb : null);

        return [
            'id' => $l->id,
            'po_id' => $po->id,
            'po_number' => $po->number,
            'po_date' => $po->date?->toDateString(),
            'is_manual' => $po->accurate_po_id === null,
            'vendor_name' => $l->vendor?->name ?? $po->vendor?->name,
            'item_code' => $l->item?->code,
            'description' => $l->description_raw ?: $l->item?->description,
            'qty' => $l->qty,
            // received so far (STOCKWISE: counted by its RI; Accurate: the RI lines of it) — a partial one reads "10/5"
            'received' => round(max((float) $l->qty_received, (float) $l->riLines->sum('kuantitas')), 2),
            'unit' => $l->unit?->code,
            'status' => $l->line_status,
            // Accurate's own PODET.REQID/REQSEQ -> APITMDET.POID/POSEQ chain, per line.
            'ppb_no' => $ppb?->no_ppb,
            'ppb_date' => $ppb?->tgl_ppb?->toDateString(),
            'ri_no' => $this->joinDistinct($l->riLines->pluck('no_ri')),
            'ri_date' => $this->joinDistinct($l->riLines->pluck('tgl_ri')->filter()->map(fn ($d) => $d->toDateString())),
            'divisi' => $ppb?->divisi,
            'keterangan' => $po->notes,
            'accurate_po_id' => $po->accurate_po_id,
        ];
    }

    private function row(PurchaseOrder $p): array
    {
        $items = $p->relationLoaded('items') ? $p->items : collect();
        $ppbs = $items->pluck('accuratePpb')->filter();
        $riLines = $items->pluck('riLines')->flatten();

        return [
            'id' => $p->id, 'number' => $p->number, 'status' => $p->status,
            'is_manual' => $p->accurate_po_id === null,
            'date' => $p->date?->toDateString(),
            'vendor_name' => $p->relationLoaded('vendor') ? $p->vendor?->name : null,
            'items_count' => $p->items_count,
            'total' => $p->total, 'created_at' => $p->created_at, 'approved_at' => $p->approved_at,
            'accurate_po_id' => $p->accurate_po_id, 'accurate_synced_at' => $p->accurate_synced_at,
            // Chain PPB -> PO -> RI is per line (Accurate's own PODET/APITMDET),
            // not a single header field — a PO with several lines can legitimately
            // span several PPB/RI documents, so these join every distinct one.
            'item_code' => $this->joinDistinct($items->pluck('item.code')),
            'description' => $this->joinDistinct($items->map(fn ($l) => $l->description_raw ?: $l->item?->description)),
            'ppb_no' => $this->joinDistinct($ppbs->pluck('no_ppb')),
            'ppb_date' => $this->joinDistinct($ppbs->pluck('tgl_ppb')->filter()->map(fn ($d) => $d->toDateString())),
            'ri_no' => $this->joinDistinct($riLines->pluck('no_ri')),
            'ri_date' => $this->joinDistinct($riLines->pluck('tgl_ri')->filter()->map(fn ($d) => $d->toDateString())),
            'divisi' => $this->joinDistinct($ppbs->pluck('divisi')),
            'keterangan' => $p->notes,
        ];
    }
}
