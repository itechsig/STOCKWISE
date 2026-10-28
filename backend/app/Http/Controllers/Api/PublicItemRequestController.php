<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemRequest;
use App\Models\ItemRequestLink;
use App\Services\ItemRequestApprovalService;
use App\Services\ItemRequestFulfillmentService;
use App\Services\ItemRequestService;
use App\Support\ItemRequestForms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Request Barang form opened through a share link — no account,
 * /api/public/item-request/{token}. It SENDS a request and gets back its number
 * and track token; with that token (Cek Permintaan) the requester follows the
 * status — nothing else is readable. Throttled per IP (routes).
 */
class PublicItemRequestController extends Controller
{
    public function __construct(private ItemRequestService $service) {}

    /** GET — is the form open? (the page shows "ditutup" otherwise) */
    public function show(string $token): JsonResponse
    {
        $link = $this->link($token);

        return response()->json(['data' => ['label' => $link->label, 'is_active' => $link->is_active, 'form' => ItemRequestForms::describe($link->form_type ?? 'UMUM')]]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        abort_unless($link->is_active, 403, 'Form request sedang ditutup oleh admin gudang.');
        // honeypot: a field people never see — bots fill it in
        abort_if(filled($request->input('website')), 422, 'Permintaan ditolak.');

        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:150'],
            'requester_contact' => ['nullable', 'string', 'max:60'],
            'form_detail' => ['nullable', 'string', 'max:200'],
            'divisi' => ['nullable', 'string', 'max:100'],
            'needed_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.deskripsi_barang' => ['required', 'string', 'max:400'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'lines.*.satuan' => ['nullable', 'string', 'max:30'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            // the images of each item (beside its name)
            'lines.*.images' => ['nullable', 'array', 'max:3'],
            'lines.*.images.*' => ['required', 'string', 'max:12000000'],
            // up to 3 images of what is asked (photo of the broken part, an example...)
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['required', 'string', 'max:12000000'],
        ], ['images.max' => 'Gambar maksimal 3.', 'lines.*.images.max' => 'Gambar maksimal 3 per barang.']);
        $made = $this->service->create([
            'name' => trim($data['requester_name']),
            'contact' => $data['requester_contact'] ?? null,
            'ip' => $request->ip(),
            'link_id' => $link->id,
        ], [
            // the form comes from the link, never from what the sender posts
            'form_type' => $link->form_type ?? 'UMUM',
            'form_detail' => $data['form_detail'] ?? null,
            'divisi' => $data['divisi'] ?? null,
            'needed_date' => $data['needed_date'] ?? null,
            'note' => $data['note'] ?? null,
            // free text only — a link never picks master items itself
            'lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['deskripsi_barang', 'qty', 'satuan', 'note', 'images'])), $data['lines']),
            'images' => $data['images'] ?? [],
        ]);
        $link->forceFill(['last_used_at' => now()])->saveQuietly();

        return response()->json(['data' => ['number' => $made->number, 'track_token' => $made->track_token]], 201);
    }

    /** GET /api/public/request-status?tokens=a,b,c — status of the requests whose track tokens the requester holds. */
    public function status(Request $request): JsonResponse
    {
        $tokens = collect(explode(',', (string) $request->query('tokens', '')))
            ->map(fn ($t) => trim($t))->filter(fn ($t) => preg_match('/^[A-Za-z0-9]{20,40}$/', $t))->unique()->take(30);
        if ($tokens->isEmpty()) {
            return response()->json(['data' => []]);
        }
        $rows = ItemRequest::with(['lines', 'approvals', 'processor:id,name', 'fulfillments.lines'])->whereIn('track_token', $tokens)->orderByDesc('id')->get();
        $lineImages = ItemRequestService::lineImages($rows->flatMap(fn ($r) => $r->lines->pluck('id')));

        return response()->json(['data' => $rows->map(function (ItemRequest $r) use ($lineImages) {
            // the lines prepared from stock, waiting for their serah terima
            $prepared = $r->fulfillments->filter(fn ($f) => in_array($f->status, ItemRequestFulfillmentService::OPEN, true))
                ->flatMap(fn ($f) => $f->lines->pluck('item_request_line_id'))->map(fn ($id) => (int) $id)->unique()->values()->all();

            return [
                'token' => $r->track_token, 'number' => $r->number, 'form_label' => ItemRequestForms::label($r->form_type),
                'status' => $r->status, 'status_label' => ItemRequestFulfillmentService::statusLabel($r),
                'requester_name' => $r->requester_name, 'divisi' => $r->divisi,
                'needed_date' => $r->needed_date?->toDateString(), 'created_at' => $r->created_at?->toIso8601String(),
                'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'images' => $lineImages[$l->id] ?? [], 'deskripsi_barang' => $l->deskripsi_barang, 'qty' => $l->qty, 'satuan' => $l->satuan,
                    'decision' => $l->decision, 'reject_reason' => $l->reject_reason,
                    // how much was approved / already handed over, and where this item stands
                    'approved_qty' => $l->approved_qty, 'fulfilled_qty' => (float) $l->fulfilled_qty,
                    'line_status_label' => ItemRequestFulfillmentService::lineLabel($l, $r, ItemRequestFulfillmentService::lineStatus($l, $r, $prepared, false))]),
                'timeline' => ItemRequestApprovalService::timeline($r),
                'received_by' => $r->received_by_name,
                // the images attached to the request (GET public/request-status/image?token=&slot=)
                'images' => ItemRequestService::images($r->id),
                // goods prepared from stock: the requester can pick them up at the warehouse
                'ready_to_pick' => $prepared !== [],
            ];
        })]);
    }

    /**
     * POST /api/public/request-status/lookup {name} — find one's requests by name (any device).
     * The whole name or its first name(s) must match — "Fauzan" finds "Fauzan Afif", "Fau" doesn't —
     * case and spaces don't matter. The newest 20 of the last 6 months.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:150']]);
        $norm = fn (?string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
        $q = $norm($data['name']);
        abort_if(mb_strlen($q) < 3, 422, 'Nama minimal 3 huruf.');
        $tokens = ItemRequest::whereNotNull('track_token')->where('created_at', '>=', now()->subMonths(6))
            ->whereRaw('LOWER(requester_name) LIKE ?', [str_replace(['%', '_'], ['\%', '\_'], explode(' ', $q)[0]).'%'])
            ->orderByDesc('id')->limit(300)->get(['requester_name', 'track_token'])
            ->filter(fn ($r) => ($n = $norm($r->requester_name)) === $q || str_starts_with($n, $q.' '))
            ->take(20)->pluck('track_token')->values();
        abort_if($tokens->isEmpty(), 404, 'Belum ada permintaan atas nama ini. Tulis nama seperti saat mengisi form.');

        return response()->json(['data' => ['tokens' => $tokens]]);
    }

    /** GET /api/public/request-status/image?token=&slot= — an image of a request, for whoever holds its track token. */
    public function image(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $token = (string) $request->query('token', '');
        $slot = (string) $request->query('slot', '');
        abort_unless(preg_match('/^[A-Za-z0-9]{20,40}$/', $token) && in_array($slot, ['img1', 'img2', 'img3'], true), 404);
        $id = ItemRequest::where('track_token', $token)->value('id');
        // ?line= : an image of one item of that request
        $line = $request->integer('line');
        if ($id && $line) {
            abort_unless(\App\Models\ItemRequestLine::where(['id' => $line, 'item_request_id' => $id])->exists(), 404);
        }
        $p = $id ? \App\Models\TrackingPhoto::where(['entity' => $line ? ItemRequestService::LINE_IMAGE_ENTITY : ItemRequestService::IMAGE_ENTITY, 'entity_id' => $line ?: $id, 'slot' => $slot])->first() : null;
        abort_unless($p && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($p->path, null, ['Content-Type' => $p->mime ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    private function link(string $token): ItemRequestLink
    {
        $link = ItemRequestLink::where('token', $token)->first();
        abort_unless($link, 404, 'Link tidak ditemukan atau sudah dihapus.');

        return $link;
    }
}
