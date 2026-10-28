<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Ri;
use App\Models\TrackingPhoto;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Retur Pembelian (purchase_return.view / .manage) — see PurchaseReturnService.
 * Made from an RI; STOCKWISE only.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReturn::query()->withCount('lines')
            ->withSum('lines as total_qty', 'qty')
            ->with('lines:id,purchase_return_id,deskripsi_barang,qty,satuan'); // the list shows what was returned, with its qty
        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('no_ri', 'like', "%{$s}%")
                ->orWhere('no_po', 'like', "%{$s}%")->orWhere('vendor', 'like', "%{$s}%")
                ->orWhereHas('lines', fn ($l) => $l->where('deskripsi_barang', 'like', "%{$s}%")->orWhere('kode_barang', 'like', "%{$s}%")));
        }
        // still open first: not sent yet, then waiting for the vendor
        $query->orderByRaw("CASE status WHEN 'DIBUAT' THEN 0 WHEN 'DIKIRIM' THEN 1 ELSE 2 END")->orderByDesc('id');
        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseReturn $r) => $this->present($r)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /purchase-returns/ri-search?q= — RI documents to return from (newest first). */
    public function riSearch(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();
        $rows = Ri::query()->whereNotNull('no_ri')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('no_ri', 'like', "%{$q}%")->orWhere('vendor', 'like', "%{$q}%")
                ->orWhere('no_po', 'like', "%{$q}%")->orWhere('deskripsi_barang', 'like', "%{$q}%")->orWhere('kode_barang', 'like', "%{$q}%")))
            ->selectRaw('no_ri, MAX(tgl_ri) as tgl_ri, MAX(vendor) as vendor, MAX(no_po) as no_po, COUNT(*) as line_count')
            ->groupBy('no_ri')->orderByRaw('MAX(tgl_ri) DESC, MAX(id) DESC')->limit(15)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'no_ri' => $r->no_ri, 'tgl_ri' => $r->tgl_ri ? substr((string) $r->tgl_ri, 0, 10) : null,
            'vendor' => $r->vendor, 'no_po' => $r->no_po, 'line_count' => (int) $r->line_count,
        ])]);
    }

    /** GET /purchase-returns/ri-lines?no_ri= — the RI's lines with how much can still be returned. */
    public function riLines(Request $request): JsonResponse
    {
        $noRi = $request->string('no_ri')->trim()->value();
        abort_if($noRi === '', 422, 'Pilih RI.');
        $first = Ri::where('no_ri', $noRi)->first();
        abort_unless($first, 404, 'RI tidak ditemukan.');

        return response()->json(['data' => [
            'no_ri' => $noRi, 'tgl_ri' => $first->tgl_ri?->toDateString(), 'vendor' => $first->vendor,
            'no_po' => Ri::where('no_ri', $noRi)->whereNotNull('no_po')->value('no_po'),
            'lines' => $this->service->riLines($noRi),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no_ri' => ['required', 'string', 'max:60'],
            'tgl_retur' => ['nullable', 'date'],
            // simple: no settlement to pick (kept for older clients)
            'resolution' => ['nullable', Rule::in(PurchaseReturn::RESOLUTIONS)],
            'reason' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            // the goods going back: the proof
            'photo' => ['required', 'string', 'max:12000000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.ri_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
        ], [
            'photo.required' => 'Foto barang yang diretur wajib diupload.',
            'reason.required' => 'Isi alasan retur.',
        ]);
        $made = $this->service->create($request->user(), $data);

        return $this->show($made)->setStatusCode(201);
    }

    public function show(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $r = $purchaseReturn->load(['lines', 'creator:id,name', 'sender:id,name', 'resolver:id,name']);
        $photos = TrackingPhoto::where(['entity' => PurchaseReturnService::PHOTO_ENTITY, 'entity_id' => $r->id])->pluck('slot');
        $iso = fn ($t) => $t?->copy()->timezone(config('app.timezone'))->toIso8601String();

        return response()->json(['data' => $this->present($r) + [
            'note' => $r->note,
            'lines' => $r->lines->map(fn (PurchaseReturnLine $l) => [
                'id' => $l->id, 'ri_id' => $l->ri_id, 'kode_barang' => $l->kode_barang, 'deskripsi_barang' => $l->deskripsi_barang,
                'qty' => $l->qty, 'satuan' => $l->satuan, 'harga_satuan' => $l->harga_satuan, 'ri_qty' => $l->ri_qty,
            ]),
            'ri_id' => Ri::where('no_ri', $r->no_ri)->orderBy('id')->value('id'),
            'created_by_name' => $r->creator?->name, 'created_at' => $iso($r->created_at),
            'sent_at' => $iso($r->sent_at), 'sent_by_name' => $r->sender?->name, 'sent_note' => $r->sent_note,
            'resolved_at' => $iso($r->resolved_at), 'resolved_by_name' => $r->resolver?->name,
            'resolution_ref' => $r->resolution_ref, 'resolution_note' => $r->resolution_note,
            'cancelled_at' => $iso($r->cancelled_at),
            'photos' => $photos->values(),
        ]]);
    }

    /** POST /purchase-returns/{id}/send {note?, photo?} */
    public function send(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255'], 'photo' => ['nullable', 'string', 'max:12000000']]);
        $this->service->send($purchaseReturn, $request->user(), $data['note'] ?? null, $data['photo'] ?? null);

        return $this->show($purchaseReturn->refresh());
    }

    /** POST /purchase-returns/{id}/resolve {ref?, note?} */
    public function resolve(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $data = $request->validate(['ref' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->service->resolve($purchaseReturn, $request->user(), $data['ref'] ?? null, $data['note'] ?? null);

        return $this->show($purchaseReturn->refresh());
    }

    public function cancel(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->service->cancel($purchaseReturn);

        return $this->show($purchaseReturn->refresh());
    }

    /** GET /purchase-returns/{id}/photo/{slot} — photo of the goods (barang) or of the shipment (kirim). */
    public function photo(PurchaseReturn $purchaseReturn, string $slot): StreamedResponse
    {
        $p = TrackingPhoto::where(['entity' => PurchaseReturnService::PHOTO_ENTITY, 'entity_id' => $purchaseReturn->id, 'slot' => $slot])->first();
        abort_unless($p && Storage::disk('local')->exists($p->path), 404);

        return Storage::disk('local')->response($p->path, null, ['Content-Type' => $p->mime ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    private function present(PurchaseReturn $r): array
    {
        $lines = $r->relationLoaded('lines') ? $r->lines : null;

        return [
            'id' => $r->id, 'number' => $r->number, 'tgl_retur' => $r->tgl_retur?->toDateString(),
            'no_ri' => $r->no_ri, 'no_po' => $r->no_po, 'vendor' => $r->vendor,
            'status' => $r->status, 'status_label' => PurchaseReturnService::STATUS_LABELS[$r->status] ?? $r->status,
            'resolution' => $r->resolution, 'resolution_label' => PurchaseReturnService::RESOLUTION_LABELS[$r->resolution] ?? $r->resolution,
            'reason' => $r->reason,
            'lines_count' => $r->lines_count ?? $lines?->count() ?? 0,
            'total_qty' => (float) ($r->total_qty ?? $lines?->sum('qty') ?? 0),
            'total_value' => $lines ? round($lines->sum(fn ($l) => $l->qty * (float) $l->harga_satuan), 2) : null,
            // the returned items with their qty (list)
            'items' => $lines ? $lines->map(fn ($l) => ['deskripsi_barang' => $l->deskripsi_barang, 'qty' => (float) $l->qty, 'satuan' => $l->satuan])->values() : [],
        ];
    }
}
