<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemRequest;
use App\Models\ItemRequestFulfillment;
use App\Models\ItemRequestLine;
use App\Models\TrackingPhoto;
use App\Services\ItemRecommender;
use App\Services\ItemRequestApprovalService;
use App\Services\ItemRequestFulfillmentService;
use App\Services\ItemRequestService;
use App\Support\ItemRequestForms;
use App\Support\Network\IpClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Request Barang — karyawan create & follow their own requests
 * (item_request.create); admin gudang sees all (item_request.view) and turns
 * the lines into NPBG / PPB (item_request.process), see ItemRequestService.
 * An NPBG request then goes through Approval (Manager → Direktur), see
 * ItemRequestApprovalService; Gudang then gives from real stock in one or more
 * NPBG + serah terima (item_request.handover), see ItemRequestFulfillmentService.
 */
class ItemRequestController extends Controller
{
    public function __construct(private ItemRequestService $service, private ItemRecommender $recommender, private ItemRequestApprovalService $approvals, private ItemRequestFulfillmentService $fulfillment) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ItemRequest::query()->withCount('lines')
            ->withCount(['lines as open_lines_count' => fn ($q) => $q->whereNull('decision')]);
        if (! $user->hasPermission('item_request.view')) {
            $query->where('requester_id', $user->id); // a karyawan sees only their own
        }
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('created_at', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('created_at', '<=', $v));
        $request->whenFilled('form_type', fn ($v) => $query->where('form_type', strtoupper((string) $v)));
        $request->whenFilled('urgency', fn ($v) => $query->where('urgency', strtoupper((string) $v)));
        if ($s = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('requester_name', 'like', "%{$s}%")
                ->orWhere('divisi', 'like', "%{$s}%")->orWhere('form_detail', 'like', "%{$s}%")
                ->orWhereHas('lines', fn ($l) => $l->where('deskripsi_barang', 'like', "%{$s}%")->orWhere('deskripsi_peminta', 'like', "%{$s}%")->orWhere('kode_barang', 'like', "%{$s}%")));
        }
        // the requesters in view (for the "Pemohon" filter) and how many requests sit at each status (the flow tabs):
        // both follow every filter except the status itself
        // (the requester list ignores the requester filter itself, so another one can be picked)
        $requesters = (clone $query)->whereNotNull('requester_name')->select('requester_name')->distinct()->orderBy('requester_name')->limit(300)->pluck('requester_name');
        $request->whenFilled('requester', fn ($v) => $query->where('requester_name', (string) $v));
        $byStatus = null;
        $request->whenFilled('status', function ($v) use ($query, &$byStatus) {
            $byStatus = (clone $query);
            $v = strtoupper((string) $v);
            if ($v === 'APPROVAL') {
                $query->whereIn('status', ['APPROVAL_MANAGER', 'APPROVAL_DIREKTUR', 'DISIAPKAN', 'SIAP_DISERAHKAN']);
            } elseif ($v === 'BERJALAN') {
                // approved, not finished: Gudang still owes something
                $query->whereIn('status', ItemRequest::FULFILMENT_STATUSES);
            } elseif (str_contains($v, ',')) {
                $query->whereIn('status', array_filter(array_map('trim', explode(',', $v))));
            } else {
                $query->where('status', $v);
            }
        });
        // still to do first, and among those the urgent ones
        $query->orderByRaw("CASE WHEN status = 'MENUNGGU' THEN 0 WHEN status IN ('DIPROSES', 'PERLU_PERBAIKAN') THEN 1 WHEN status IN ('APPROVAL_MANAGER', 'APPROVAL_DIREKTUR', 'DISIAPKAN', 'SIAP_DISERAHKAN') THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN status IN ('MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN') AND urgency = 'URGENT' THEN 0 ELSE 1 END")
            ->orderByDesc('id');
        $counts = ($byStatus ?? (clone $query))->reorder()->select('status')->selectRaw('count(*) as n')->groupBy('status')->pluck('n', 'status');
        $page = $query->with('lines:id,item_request_id,deskripsi_barang,qty,approved_qty,fulfilled_qty,satuan,decision')->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ItemRequest $r) => $this->present($r) + [
                // first items asked, for the list
                'items_preview' => $r->lines->take(2)->pluck('deskripsi_barang')->values(),
                // per item: asked (or approved) / handed over so far — a partial handover reads "10/5"
                'progress' => $r->lines->where('decision', '!=', 'TOLAK')->values()->map(fn ($l) => [
                    'name' => $l->deskripsi_barang, 'qty' => (float) ($l->approved_qty ?? $l->qty), 'done' => (float) $l->fulfilled_qty, 'unit' => $l->satuan,
                ]),
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'status_counts' => (object) $counts->map(fn ($n) => (int) $n)->all(), 'requesters' => $requesters],
        ]);
    }

    /** GET /api/item-request-forms — the forms (ATK, Maintenance General, …) and what each one asks. */
    public function forms(): JsonResponse
    {
        return response()->json(['data' => ItemRequestForms::all()]);
    }

    public function show(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $this->authorizeView($request, $itemRequest);
        $itemRequest->load(['lines.npbg:id,no_npbg', 'lines.ppb:id,no_ppb', 'lines.ppbDraft:id,number,status', 'lines.item:id,code,description', 'lines.item.snapshot:item_id,available,stock_known',
            'processor:id,name', 'approvals', 'handedOverBy:id,name', 'fulfillments.lines', 'fulfillments.preparer:id,name', 'fulfillments.hander:id,name']);
        $user = $request->user();
        // 5 stock recommendations per line still to decide — only for whoever processes requests
        $recommend = $user->hasPermission('item_request.process');
        $gudang = $user->hasPermission(ItemRequestApprovalService::HANDOVER_PERMISSION);
        // the NPBG not handed over yet: DISIAPKAN (Gudang fixes the quantities) or SIAP (ready for the serah terima)
        $ready = $itemRequest->fulfillments->first(fn ($f) => in_array($f->status, ItemRequestFulfillmentService::OPEN, true));
        $preparedLines = $ready ? $ready->lines->pluck('item_request_line_id')->all() : [];
        $fulfilling = in_array($itemRequest->status, ItemRequest::FULFILMENT_STATUSES, true);
        // the stock column: live from the warehouse rows, not the analysis snapshot (follows every RI / NPBG at once)
        $stock = ItemRequestFulfillmentService::availableMany($itemRequest->lines->pluck('item_id'));
        $onHand = ItemRequestFulfillmentService::onHandMany($itemRequest->lines->pluck('item_id'));
        $lineNames = $itemRequest->lines->mapWithKeys(fn ($l) => [$l->id => [$l->item?->description ?? $l->deskripsi_barang, $l->satuan]]);
        // the NPBG / PPB columns: what leaves the stock (NPBG) and what has to be bought (PPB) per line
        $issued = [];
        foreach ($itemRequest->fulfillments as $f) {
            foreach ($f->lines as $fl) {
                $issued[$fl->item_request_line_id]['qty'] = ($issued[$fl->item_request_line_id]['qty'] ?? 0) + (float) ($fl->handed_qty ?? $fl->qty);
                $issued[$fl->item_request_line_id]['docs'][$f->no_npbg] = ['no' => $f->no_npbg, 'npbg_id' => $fl->npbg_id, 'handed' => $f->status === 'DISERAHKAN'];
            }
        }
        $buying = \Illuminate\Support\Facades\DB::table('ppb_draft_lines as dl')->join('ppb_drafts as d', 'd.id', '=', 'dl.ppb_draft_id')
            ->whereIn('dl.item_request_line_id', $itemRequest->lines->pluck('id'))->where('d.status', '!=', 'DIBATALKAN')
            ->get(['dl.item_request_line_id', 'dl.qty', 'dl.ppb_id', 'd.id as draft_id', 'd.number'])->groupBy('item_request_line_id');
        $ppbApproval = \App\Services\PpbApprovalService::statusFor($itemRequest->lines->pluck('ppb.no_ppb')->filter());
        $purchase = function (ItemRequestLine $l) use ($buying, $ppbApproval) {
            $rows = $buying->get($l->id);
            if (! $rows) {
                return null;
            }
            $qty = round((float) $rows->sum('qty'), 2);
            $po = $l->ppb_id ? \Illuminate\Support\Facades\DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
                ->where('i.approved_ppb_id', $l->ppb_id)->where('o.status', '!=', 'CANCELLED')->orderByDesc('o.id')->first(['o.id', 'o.number', 'o.status', 'i.qty', 'i.qty_received']) : null;
            $stage = match (true) {
                $po && (float) $po->qty_received >= (float) $po->qty - 0.0001 => 'Diterima (RI)',
                $po && (float) $po->qty_received > 0 => 'Diterima sebagian (RI)',
                $po && $po->status === 'DIBELI' => 'Sudah dibeli, menunggu RI',
                $po && in_array($po->status, ['APPROVED', 'SENT'], true) => 'PO dikirim, menunggu RI',
                (bool) $po => 'PO menunggu approval',
                (bool) $l->ppb_id => 'PPB '.(['MENUNGGU' => 'menunggu Manajer', 'MENUNGGU_DIREKTUR' => 'menunggu Direktur', 'DISETUJUI' => 'disetujui, menunggu PO', 'DITOLAK' => 'ditolak'][$ppbApproval[$l->ppb?->no_ppb]['status'] ?? ''] ?? 'diajukan'),
                default => 'Draft PPB',
            };

            return ['qty' => $qty, 'stage' => trim($stage), 'draft_id' => $rows->first()->draft_id, 'draft_number' => $rows->first()->number,
                'ppb_id' => $l->ppb_id, 'no_ppb' => $l->ppb?->no_ppb, 'po_id' => $po?->id, 'po_number' => $po?->number];
        };

        $lineImages = ItemRequestService::lineImages($itemRequest->lines->pluck('id'));

        return response()->json(['data' => $this->present($itemRequest) + [
            // the requester's images, shown from the request to its end
            'images' => ItemRequestService::images($itemRequest->id),
            'processed_by_name' => $itemRequest->processor?->name,
            'timeline' => ItemRequestApprovalService::timeline($itemRequest),
            'approval_log' => $itemRequest->approvals->sortByDesc('id')->values()->map(fn ($a) => $this->step($a)),
            // what this user can do now: approve / reject this step, or the handover
            'can_approve' => isset(ItemRequestApprovalService::STAGES[$itemRequest->status]) && ItemRequestApprovalService::canAct($user, $itemRequest),
            // MANAGER / DIREKTUR / GUDANG — a Direktur at the Manager step approves as Direktur (bypass)
            'approve_as' => ItemRequestApprovalService::actsAs($user, $itemRequest),
            // Gudang: give from stock now (no NPBG waiting for its serah terima), hand over the prepared one, close what is left
            // (more goods may join the NPBG still waiting for its serah terima)
            'can_fulfill' => $gudang && $fulfilling && ($plan = $this->fulfillment->plan($itemRequest)) !== [] && collect($plan)->contains(fn ($p) => ($p['available'] ?? 0) > 0 || ! $p['in_procurement']),
            // one step at Gudang: the prepared NPBG is handed over (its quantities fixed in the serah terima)
            'can_handover' => $gudang && (bool) $ready,
            'can_close' => $fulfilling && ! $ready && ($gudang || $user->hasPermission('item_request.process')),
            'plan' => $gudang && $fulfilling ? ($plan ?? $this->fulfillment->plan($itemRequest)) : [],
            'fulfillments' => $itemRequest->fulfillments->map(fn (ItemRequestFulfillment $f) => [
                'id' => $f->id, 'seq' => $f->seq, 'status' => $f->status, 'no_npbg' => $f->no_npbg,
                'prepared_at' => $f->prepared_at?->copy()->timezone(config('app.timezone'))->toIso8601String(), 'prepared_by' => $f->preparer?->name,
                'handed_at' => $f->handed_at?->copy()->timezone(config('app.timezone'))->toIso8601String(), 'handed_by' => $f->hander?->name,
                'received_by' => $f->received_by_name, 'note' => $f->note,
                'photo' => $f->status === 'DISERAHKAN' && $this->hasFile($f->id, 'handover', ItemRequestFulfillmentService::PHOTO_ENTITY),
                'signature' => $f->status === 'DISERAHKAN' && $this->hasFile($f->id, 'signature', ItemRequestFulfillmentService::PHOTO_ENTITY),
                'lines' => $f->lines->map(fn ($fl) => [
                    'id' => $fl->id, 'line_id' => $fl->item_request_line_id, 'deskripsi' => $lineNames[$fl->item_request_line_id][0] ?? null,
                    'satuan' => $lineNames[$fl->item_request_line_id][1] ?? null, 'qty' => (float) $fl->qty,
                    'handed_qty' => $fl->handed_qty !== null ? (float) $fl->handed_qty : null, 'npbg_id' => $fl->npbg_id,
                ])->values(),
            ])->values(),
            'handover' => $itemRequest->handed_over_at ? [
                'at' => $itemRequest->handed_over_at->copy()->timezone(config('app.timezone'))->toIso8601String(),
                'by' => $itemRequest->handedOverBy?->name, 'received_by' => $itemRequest->received_by_name, 'note' => $itemRequest->handover_note,
                'photo' => $this->hasFile($itemRequest->id, 'handover'), 'signature' => $this->hasFile($itemRequest->id, 'signature'),
            ] : null,
            // the requester's own Cek Permintaan link (opens without login)
            'track_token' => $itemRequest->requester_id === $user->id || $user->hasPermission('item_request.process') ? $itemRequest->track_token : null,
            'lines' => $itemRequest->lines->map(fn (ItemRequestLine $l) => [
                // the images of this item (beside its name)
                'images' => $lineImages[$l->id] ?? [],
                'id' => $l->id, 'item_id' => $l->item_id, 'kode_barang' => $l->kode_barang, 'deskripsi_barang' => $l->deskripsi_barang,
                // what the requester typed (the name above is Master Produk's once matched)
                'deskripsi_peminta' => $l->deskripsi_peminta,
                'qty' => $l->qty, 'satuan' => $l->satuan, 'note' => $l->note,
                'decision' => $l->decision, 'reject_reason' => $l->reject_reason,
                // approved (official need) → handed over → what is left; closed with a reason
                'approved_qty' => $l->approved_qty, 'fulfilled_qty' => (float) $l->fulfilled_qty, 'remaining' => $l->remaining(),
                'closed_at' => $l->closed_at, 'closed_reason' => $l->closed_reason,
                'line_status' => $s = ItemRequestFulfillmentService::lineStatus($l, $itemRequest, $preparedLines, false),
                'line_status_label' => ItemRequestFulfillmentService::lineLabel($l, $itemRequest, $s),
                // given from stock (NPBG) / to be bought (PPB → PO → RI)
                'npbg' => isset($issued[$l->id]) ? ['qty' => round($issued[$l->id]['qty'], 2), 'docs' => array_values($issued[$l->id]['docs'])] : null,
                'ppb' => $purchase($l),
                'npbg_id' => $l->npbg_id, 'no_npbg' => $l->npbg?->no_npbg, 'ppb_id' => $l->ppb_id, 'no_ppb' => $l->ppb?->no_ppb,
                // a PPB line waits in a Draft PPB until it is submitted
                'ppb_draft_id' => $l->ppb_draft_id, 'ppb_draft_number' => $l->ppbDraft?->number, 'ppb_draft_status' => $l->ppbDraft?->status,
                'decided_at' => $l->decided_at,
                'item_description' => $l->item?->description,
                // warehouse stock of the matched item now (null when not matched / unknown)
                'available' => $l->item_id && array_key_exists($l->item_id, $stock) ? $stock[$l->item_id] : null,
                // the stock itself (Master Produk): what prepared NPBG hold leaves it only with the serah terima
                'on_hand' => $l->item_id && array_key_exists($l->item_id, $onHand) ? $onHand[$l->item_id] : null,
                // every good match, best first (the page shows 5 and scrolls the rest)
                'recommendations' => $recommend && $l->decision === null ? $this->recommender->recommend($l->requestedText(), 30) : [],
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'form_type' => ['nullable', Rule::in(array_keys(ItemRequestForms::TYPES))],
            'form_detail' => ['nullable', 'string', 'max:200'],
            'divisi' => ['nullable', 'string', 'max:100'],
            'needed_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.kode_barang' => ['nullable', 'string', 'max:60'],
            'lines.*.deskripsi_barang' => ['required', 'string', 'max:400'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.01'],
            'lines.*.satuan' => ['nullable', 'string', 'max:30'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            // the images of each item (beside its name)
            'lines.*.images' => ['nullable', 'array', 'max:3'],
            'lines.*.images.*' => ['required', 'string', 'max:12000000'],
            // up to 3 images of what is asked (photo of the broken part, an example...)
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['required', 'string', 'max:12000000'],
        ], ['images.max' => 'Gambar maksimal 3.', 'lines.*.images.max' => 'Gambar maksimal 3 per barang.']);
        $user = $request->user();
        $made = $this->service->create(['id' => $user->id, 'name' => $user->name, 'ip' => $request->ip()], $data);

        return $this->show($request, $made)->setStatusCode(201);
    }

    /**
     * POST /api/item-requests/{id}/process {lines: {line id: {item_id, qty}}, npbg: {pelanggan, tipe_npbg}} —
     * admin gudang verifies (matches the master item, may propose less) and the request goes to approval;
     * NPBG or PPB is decided by the system from the stock once it is approved.
     */
    public function process(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.01'],
            'npbg' => ['nullable', 'array'],
            'npbg.pelanggan' => ['nullable', 'string', 'max:200'],
            'npbg.tipe_npbg' => ['nullable', 'in:Penjualan,Non Penjualan'],
        ]);
        $this->service->process($itemRequest, $request->user(), (array) ($data['lines'] ?? $request->input('lines', [])), $data['npbg'] ?? []);

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * PUT /api/item-requests/{id}/lines/{line}/match {item_id} — admin gudang picks the Master Produk item of a
     * line while verifying: saved at once (the Item table shows it), the request is still sent to approval later.
     */
    public function matchLine(Request $request, ItemRequest $itemRequest, ItemRequestLine $line): JsonResponse
    {
        abort_unless($line->item_request_id === $itemRequest->id, 404);
        $data = $request->validate(['item_id' => ['required', 'integer', 'exists:items,id']]);
        if (! in_array($itemRequest->status, ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'], true) || $line->decision !== null) {
            abort(422, 'Barang ini sudah diverifikasi.');
        }
        $item = \App\Models\Item::with('unit:id,code')->findOrFail($data['item_id']);
        abort_unless($item->is_active, 422, "{$item->description} nonaktif di Master Produk, aktifkan atau pilih barang lain.");
        $line->matchTo($item)->save();

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * PUT /api/item-requests/{id}/urgency {urgency: URGENT|NORMAL|null} — admin gudang
     * decides what is urgent (the requester can't: everyone would pick urgent).
     * Urgent requests still waiting stay on top of the list.
     */
    public function urgency(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $data = $request->validate(['urgency' => ['present', 'nullable', Rule::in(ItemRequestForms::URGENCIES)]]);
        $itemRequest->update(['urgency' => $data['urgency']]);

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * GET /api/approvals — Request Barang waiting for this user's step (scope=todo, the default),
     * or the ones they already decided (scope=done). meta.counts: waiting per step.
     */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        $statuses = ItemRequestApprovalService::actionableStatuses($user);
        $query = ItemRequest::query()->with(['lines.npbg:id,no_npbg', 'approvals'])->withCount('lines')
            ->withCount(['lines as open_lines_count' => fn ($q) => $q->whereNull('decision')]);
        if ($request->input('scope') === 'done') {
            $query->whereHas('approvals', fn ($q) => $q->where('user_id', $user->id)->whereIn('action', ['APPROVE', 'REJECT', 'RETURN', 'FULFILL', 'HANDOVER']))
                ->orderByDesc('updated_at')->limit(50);
        } else {
            $stage = strtoupper((string) $request->input('stage', ''));
            $query->whereIn('status', $stage !== '' && in_array($stage, $statuses, true) ? [$stage] : $statuses)
                ->orderByRaw("CASE WHEN urgency = 'URGENT' THEN 0 ELSE 1 END")->orderBy('updated_at');
        }
        $counts = $statuses ? ItemRequest::whereIn('status', $statuses)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') : collect();

        $rows = $query->get();
        // the NPBG of a request waiting for its serah terima, with its lines (for the handover dialog)
        $openOf = function (ItemRequest $r): array {
            $names = $r->lines->mapWithKeys(fn ($l) => [$l->id => [$l->deskripsi_barang, $l->satuan]]);

            return $r->fulfillments()->whereIn('status', ItemRequestFulfillmentService::OPEN)->with('lines')->get()
                ->map(fn (ItemRequestFulfillment $f) => ['id' => $f->id, 'seq' => $f->seq, 'status' => $f->status, 'no_npbg' => $f->no_npbg,
                    'lines' => $f->lines->map(fn ($fl) => ['id' => $fl->id, 'line_id' => $fl->item_request_line_id, 'deskripsi' => $names[$fl->item_request_line_id][0] ?? null,
                        'satuan' => $names[$fl->item_request_line_id][1] ?? null, 'qty' => (float) $fl->qty, 'handed_qty' => null, 'npbg_id' => $fl->npbg_id])->values()->all(),
                ])->all();
        };
        // live stock of their items: the approver sees what the stock can give (NPBG) and what must be bought (PPB)
        $stock = ItemRequestFulfillmentService::availableMany($rows->flatMap(fn ($r) => $r->lines->pluck('item_id')));
        $onHand = ItemRequestFulfillmentService::onHandMany($rows->flatMap(fn ($r) => $r->lines->pluck('item_id')));
        $lineImages = ItemRequestService::lineImages($rows->flatMap(fn ($r) => $r->lines->pluck('id')));

        return response()->json([
            'data' => $rows->map(fn (ItemRequest $r) => $this->present($r) + [
                'images' => ItemRequestService::images($r->id),
                'lines' => $r->lines->map(fn (ItemRequestLine $l) => [
                    'images' => $lineImages[$l->id] ?? [],
                    'id' => $l->id, 'kode_barang' => $l->kode_barang, 'deskripsi_barang' => $l->deskripsi_barang, 'qty' => $l->qty,
                    'satuan' => $l->satuan, 'note' => $l->note, 'decision' => $l->decision, 'npbg_id' => $l->npbg_id, 'no_npbg' => $l->npbg?->no_npbg,
                    'approved_qty' => $l->approved_qty, 'fulfilled_qty' => (float) $l->fulfilled_qty, 'remaining' => $l->remaining(),
                    'item_id' => $l->item_id, 'available' => $l->item_id && array_key_exists($l->item_id, $stock) ? $stock[$l->item_id] : null,
                    'on_hand' => $l->item_id && array_key_exists($l->item_id, $onHand) ? $onHand[$l->item_id] : null,
                ]),
                'can_approve' => isset(ItemRequestApprovalService::STAGES[$r->status]) && in_array($r->status, $statuses, true),
                'approve_as' => ItemRequestApprovalService::actsAs($user, $r),
                // Gudang: the prepared NPBG is handed over in one step (its quantities fixed there)
                'can_handover' => in_array($r->status, ['DISIAPKAN', 'SIAP_DISERAHKAN'], true) && in_array($r->status, $statuses, true) && ($open = $openOf($r)) !== [],
                'can_fulfill' => $r->status === 'DISIAPKAN' && in_array('DISIAPKAN', $statuses, true) && $openOf($r) === [],
                'fulfillments' => $open ?? [],
                'last_step' => ($a = $r->approvals->sortByDesc('id')->first()) ? $this->step($a) : null,
            ]),
            'meta' => ['statuses' => $statuses, 'counts' => (object) $counts->map(fn ($n) => (int) $n)->all(), 'total' => (int) $counts->sum()],
        ]);
    }

    /** GET /api/approvals/count — the sidebar badge. */
    public function approvalCount(Request $request): JsonResponse
    {
        $statuses = ItemRequestApprovalService::actionableStatuses($request->user());

        $user = $request->user();
        // + STOCKWISE PPB / PO waiting for this user's approval
        $purchasing = collect(PurchasingApprovalController::PERMISSIONS)->contains(fn ($p) => $user->hasPermission($p))
            ? PurchasingApprovalController::waiting($user)['total'] : 0;

        return response()->json(['data' => ['total' => ($statuses ? ItemRequest::whereIn('status', $statuses)->count() : 0) + $purchasing,
            'requests' => $statuses ? ItemRequest::whereIn('status', $statuses)->count() : 0, 'purchasing' => $purchasing]]);
    }

    /**
     * POST /api/item-requests/{id}/approval {action: APPROVE|REJECT|RETURN, note, quantities: {line id: qty}}
     * — whoever holds the current step; quantities cannot be changed here (RETURN sends it back to admin gudang to revise them).
     */
    public function decide(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['APPROVE', 'REJECT', 'RETURN'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        // quantities are revised by admin gudang (RETURN), never by the approver
        \App\Support\ApprovalQuantities::refuseFromApprover($request->input('quantities'));
        $this->approvals->decide($itemRequest, $request->user(), $data['action'], $data['note'] ?? null, []);

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * POST /api/item-requests/{id}/fulfillments {lines: [{line_id, qty, shortage_to_ppb}]} — Gudang gives
     * what the stock has now: one NPBG (#1, #2 …); what is short may go to a Draft PPB.
     */
    public function fulfill(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'min:0'],
            'lines.*.shortage_to_ppb' => ['nullable', 'boolean'],
        ]);
        $this->fulfillment->prepare($itemRequest, $request->user(), $data['lines']);

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * POST /api/item-requests/{id}/fulfillments/{fulfillment}/handover — serah terima of one prepared NPBG:
     * photo + the receiver's signature (data URLs) + the quantities really handed over {handed: {line: qty}}.
     */
    public function handoverFulfillment(Request $request, ItemRequest $itemRequest, ItemRequestFulfillment $fulfillment): JsonResponse
    {
        abort_unless($fulfillment->item_request_id === $itemRequest->id, 404);
        $data = $request->validate([
            'photo' => ['required', 'string', 'max:12000000'],
            'signature' => ['required', 'string', 'max:3000000'],
            'received_by' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:255'],
            'handed' => ['nullable', 'array'],
            'handed.*' => ['numeric', 'min:0'],
            // lines whose part not handed over is closed (not needed any more)
            'drop' => ['nullable', 'array'],
            'drop.*' => ['integer'],
            // why less than the approved NPBG is handed over
            'reasons' => ['nullable', 'array'],
            'reasons.*' => ['nullable', 'string', 'max:200'],
        ]);
        $this->fulfillment->handover($itemRequest, $fulfillment, $request->user(), $data['photo'], $data['signature'], $data['received_by'], $data['note'] ?? null,
            collect($data['handed'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (float) $q])->all(),
            array_map('intval', $data['drop'] ?? []),
            collect($data['reasons'] ?? [])->mapWithKeys(fn ($t, $id) => [(int) $id => (string) $t])->all());

        return $this->show($request, $itemRequest->refresh());
    }

    /**
     * POST /api/item-requests/{id}/fulfillments/{fulfillment}/confirm {quantities: {line: qty}} — Gudang fixes how much
     * it hands over (less goes back to stock), then it is ready for the serah terima.
     */
    public function confirmFulfillment(Request $request, ItemRequest $itemRequest, ItemRequestFulfillment $fulfillment): JsonResponse
    {
        abort_unless($fulfillment->item_request_id === $itemRequest->id, 404);
        $data = $request->validate(['quantities' => ['nullable', 'array'], 'quantities.*' => ['numeric', 'min:0']]);
        $this->fulfillment->confirm($itemRequest, $fulfillment, $request->user(),
            collect($data['quantities'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (float) $q])->all());

        return $this->show($request, $itemRequest->refresh());
    }

    /** POST /api/item-requests/{id}/handover — older screens: the serah terima of the NPBG now prepared. */
    public function handover(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $ready = $itemRequest->fulfillments()->whereIn('status', ItemRequestFulfillmentService::OPEN)->first();
        abort_unless($ready, 422, 'Tidak ada barang yang menunggu serah terima.');

        return $this->handoverFulfillment($request, $itemRequest, $ready);
    }

    /** POST /api/item-requests/{id}/close {line_ids?, reason} — what is left is not given any more (all lines when none named). */
    public function close(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        $data = $request->validate([
            'line_ids' => ['nullable', 'array'],
            'line_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->fulfillment->close($itemRequest, $request->user(), isset($data['line_ids']) ? array_map('intval', $data['line_ids']) : null, $data['reason']);

        return $this->show($request, $itemRequest->refresh());
    }

    /** GET /api/item-requests/{id}/fulfillments/{fulfillment}/file/{slot} — that serah terima's photo or signature. */
    public function fulfillmentFile(Request $request, ItemRequest $itemRequest, ItemRequestFulfillment $fulfillment, string $slot): StreamedResponse
    {
        $this->authorizeView($request, $itemRequest);
        abort_unless($fulfillment->item_request_id === $itemRequest->id, 404);

        return $this->stream(ItemRequestFulfillmentService::PHOTO_ENTITY, $fulfillment->id, $slot);
    }

    /** GET /api/item-requests/{id}/image/{slot} — an image the requester attached (img1..img3). */
    public function image(Request $request, ItemRequest $itemRequest, string $slot): StreamedResponse
    {
        $this->authorizeView($request, $itemRequest);

        return $this->stream(ItemRequestService::IMAGE_ENTITY, $itemRequest->id, $slot);
    }

    /** GET /api/item-requests/{id}/lines/{line}/image/{slot} — an image of one item of the request. */
    public function lineImage(Request $request, ItemRequest $itemRequest, ItemRequestLine $line, string $slot): StreamedResponse
    {
        $this->authorizeView($request, $itemRequest);
        abort_unless($line->item_request_id === $itemRequest->id, 404);

        return $this->stream(ItemRequestService::LINE_IMAGE_ENTITY, $line->id, $slot);
    }

    /** GET /api/item-requests/{id}/handover/{slot} — the handover photo or the signature. */
    public function handoverFile(Request $request, ItemRequest $itemRequest, string $slot): StreamedResponse
    {
        $this->authorizeView($request, $itemRequest);

        return $this->stream(ItemRequestApprovalService::PHOTO_ENTITY, $itemRequest->id, $slot);
    }

    private function stream(string $entity, int $id, string $slot): StreamedResponse
    {
        $photo = TrackingPhoto::where(['entity' => $entity, 'entity_id' => $id, 'slot' => $slot])->first();
        abort_unless($photo && Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, null, ['Content-Type' => $photo->mime ?? 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function cancel(Request $request, ItemRequest $itemRequest): JsonResponse
    {
        abort_unless($itemRequest->requester_id === $request->user()->id || $request->user()->hasPermission('item_request.process'), 403,
            'Hanya pembuat request yang bisa membatalkan.');
        $this->service->cancel($itemRequest);

        return $this->show($request, $itemRequest->refresh());
    }

    private function authorizeView(Request $request, ItemRequest $r): void
    {
        $user = $request->user();
        abort_unless($r->requester_id === $user->id || $user->hasPermission('item_request.view')
            || collect(ItemRequestApprovalService::PERMISSIONS)->contains(fn ($p) => $user->hasPermission($p)), 403, 'Bukan request Anda.');
    }

    private function hasFile(int $id, string $slot, string $entity = ItemRequestApprovalService::PHOTO_ENTITY): bool
    {
        return TrackingPhoto::where(['entity' => $entity, 'entity_id' => $id, 'slot' => $slot])->exists();
    }

    private function step(\App\Models\ItemRequestApproval $a): array
    {
        return [
            'stage' => $a->stage, 'action' => $a->action, 'note' => $a->note, 'user_name' => $a->user_name,
            'at' => $a->created_at?->copy()->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }

    private function present(ItemRequest $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => ItemRequestFulfillmentService::statusLabel($r), 'source' => $r->source,
            'form_type' => $r->form_type, 'form_label' => ItemRequestForms::label($r->form_type), 'urgency' => $r->urgency,
            'form_detail' => $r->form_detail, 'form_detail_label' => ItemRequestForms::describe($r->form_type ?? 'UMUM')['detail_label'],
            'requester_id' => $r->requester_id, 'requester_name' => $r->requester_name, 'requester_contact' => $r->requester_contact,
            // where the request came from (office network or not) — IpClassifier
            'requester_location' => $r->requester_location ? IpClassifier::label($r->requester_location) : null,
            'divisi' => $r->divisi,
            'needed_date' => $r->needed_date?->toDateString(), 'note' => $r->note,
            'lines_count' => $r->lines_count ?? $r->lines()->count(),
            'open_lines_count' => $r->open_lines_count ?? $r->lines()->whereNull('decision')->count(),
            'processed_at' => $r->processed_at, 'created_at' => $r->created_at,
        ];
    }
}
