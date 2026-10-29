<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderQuote;
use App\Services\PoDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Draft PO: items of approved STOCKWISE PPBs, each vendor's price, picked into one PO per vendor (PoDraftService). */
class PoDraftController extends Controller
{
    public function __construct(private PoDraftService $service) {}

    /** GET /po-draft-lines?search= */
    public function lines(Request $request): JsonResponse
    {
        $q = PoDraftService::waiting()->with(['approvedPpb:id,no_ppb,tgl_ppb,divisi,peminta,tgl_diperlukan', 'item:id,code,description', 'unit:id,code',
            'quotes' => fn ($x) => $x->orderBy('unit_price')->orderBy('id'), 'quotes.vendor:id,name', 'quotes.creator:id,name']);
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $q->where(fn ($x) => $x->where('description_raw', 'like', "%{$w}%")
                ->orWhereHas('item', fn ($i) => $i->where('code', 'like', "%{$w}%")->orWhere('description', 'like', "%{$w}%"))
                ->orWhereHas('approvedPpb', fn ($p) => $p->where('no_ppb', 'like', "%{$w}%")->orWhere('peminta', 'like', "%{$w}%")->orWhere('divisi', 'like', "%{$w}%"))
                ->orWhereHas('quotes.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%")));
        }
        $rows = $q->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (PurchaseOrderItem $l) => $this->row($l))->values(), 'meta' => ['total' => $rows->count()]]);
    }

    /** PUT /po-draft-lines/{item}/quotes {vendor_id, unit_price, note?} — a vendor's price (saved again = changed). */
    public function quote(Request $request, PurchaseOrderItem $item): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->quote($item, $request->user(), (int) $data['vendor_id'], (float) $data['unit_price'], $data['note'] ?? null);

        return response()->json(['data' => $this->row($this->fresh($item))]);
    }

    /** DELETE /po-draft-lines/{item}/quotes/{quote} */
    public function removeQuote(PurchaseOrderItem $item, PurchaseOrderQuote $quote): JsonResponse
    {
        abort_unless($quote->purchase_order_item_id === $item->id, 404);
        $this->service->expectWaiting($item);
        $quote->delete();

        return response()->json(['data' => $this->row($this->fresh($item))]);
    }

    /** POST /po-draft-lines/submit {line_ids, vendor_id, expected_date?, notes?, lines?: [{id, qty?, unit_price?}]} — one PO of one vendor. */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'line_ids' => ['required', 'array', 'min:1', 'max:200'],
            'line_ids.*' => ['integer'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $po = $this->service->submit($request->user(), array_map('intval', $data['line_ids']), $data);

        return response()->json(['data' => ['id' => $po->id, 'number' => $po->number, 'total' => (float) $po->total, 'lines' => $po->items()->count()]], 201);
    }

    private function fresh(PurchaseOrderItem $item): PurchaseOrderItem
    {
        return $item->fresh(['approvedPpb:id,no_ppb,tgl_ppb,divisi,peminta,tgl_diperlukan', 'item:id,code,description', 'unit:id,code',
            'quotes' => fn ($x) => $x->orderBy('unit_price')->orderBy('id'), 'quotes.vendor:id,name', 'quotes.creator:id,name']);
    }

    private function row(PurchaseOrderItem $l): array
    {
        $p = $l->approvedPpb;

        return [
            'id' => $l->id, 'item_id' => $l->item_id, 'item_code' => $l->item?->code, 'description' => $l->description_raw,
            'qty' => (float) $l->qty, 'unit' => $l->unit?->code,
            'ppb' => $p ? ['id' => $p->id, 'no_ppb' => $p->no_ppb, 'divisi' => $p->divisi, 'peminta' => $p->peminta,
                'tgl_ppb' => $p->tgl_ppb?->toDateString(), 'tgl_diperlukan' => $p->tgl_diperlukan?->toDateString()] : null,
            // sorted cheapest first: the first is the cheapest
            'quotes' => $l->quotes->map(fn (PurchaseOrderQuote $q) => [
                'id' => $q->id, 'vendor_id' => $q->vendor_id, 'vendor_name' => $q->vendor?->name, 'unit_price' => (float) $q->unit_price,
                'note' => $q->note, 'by' => $q->creator?->name, 'updated_at' => $q->updated_at?->copy()->timezone(config('app.timezone'))->toIso8601String(),
            ])->values(),
        ];
    }
}
