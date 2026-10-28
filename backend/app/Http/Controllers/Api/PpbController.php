<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PpbResource;
use App\Models\Ppb;
use App\Services\Export\DocumentPrintRenderer;
use App\Services\PpbApprovalService;
use App\Support\PpbLineStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPB — Accurate REQUISITION/REQUISITIONDET mirror. Mostly read-only (rows come
 * from Sync Accurate, App\Services\Accurate\AccurateSyncService) — but store()
 * also allows creating a row manually for requests that never went through
 * Accurate (see Ppb model docblock for the accurate_* = null convention).
 */
class PpbController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Ppb::query()->with(
            'poItems:id,accurate_ppb_id,purchase_order_id,vendor_id',
            'poItems.purchaseOrder:id,number,date,vendor_id',
            'poItems.purchaseOrder.vendor:id,name',
            'poItems.vendor:id,name',
            'poItems.riLines:id,accurate_po_item_id,no_ri,tgl_ri',
            // a STOCKWISE PPB: the PO made from it once approved
            'approvedPoItems:id,approved_ppb_id,purchase_order_id,vendor_id',
            'approvedPoItems.purchaseOrder:id,number,date,vendor_id,status',
            'approvedPoItems.purchaseOrder.vendor:id,name',
            'approvedPoItems.vendor:id,name',
            'approvedPoItems.riLines:id,accurate_po_item_id,no_ri,tgl_ri',
        );

        // free text, word by word: every word must be found somewhere on this
        // line — no. PPB, barang, peminta, divisi, catatan, or the no. PO/RI it's linked to
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $query->where(fn ($q) => $q->where('no_ppb', 'like', "%{$w}%")
                ->orWhereLoose('kode_barang', $w)
                ->orWhereLoose('deskripsi_barang', $w)
                ->orWhere('peminta', 'like', "%{$w}%")
                ->orWhere('divisi', 'like', "%{$w}%")
                ->orWhere('keterangan', 'like', "%{$w}%")
                ->orWhere('catatan_baris', 'like', "%{$w}%")
                ->orWhereHas('poItems.purchaseOrder', fn ($p) => $p->where('number', 'like', "%{$w}%"))
                ->orWhereHas('poItems.purchaseOrder.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%"))
                ->orWhereHas('poItems.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%"))
                ->orWhereHas('poItems.riLines', fn ($r) => $r->where('no_ri', 'like', "%{$w}%")));
        }

        $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
        // status per line: REQUESTED / SHORTAGE / COMPLETED / CLOSE — AMEND = the Excel "Amend" flag (any status)
        $request->whenFilled('line_status', fn ($v) => strtoupper((string) $v) === \App\Support\PpbLineStatus::AMEND
            ? $query->where('is_amend', true)
            : $query->where('line_status', strtoupper((string) $v)));
        $request->whenFilled('amend', fn ($v) => $query->where('is_amend', filter_var($v, FILTER_VALIDATE_BOOLEAN)));
        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        // vendor of a PPB line = the vendor of the PO(s) Accurate raised from it (PODET.REQID chain)
        $request->whenFilled('vendor_id', fn ($v) => $v === 'none'
            ? $query->whereDoesntHave('poItems')
            : $query->whereHas('poItems', fn ($i) => $i->where('vendor_id', $v)
                ->orWhere(fn ($x) => $x->whereNull('vendor_id')->whereHas('purchaseOrder', fn ($p) => $p->where('vendor_id', $v)))));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ppb', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ppb', '<=', $v));
        // approval before PO (PpbApprovalService): MENUNGGU / DISETUJUI / DITOLAK / PO_TANPA_APPROVAL
        $request->whenFilled('approval', fn ($v) => self::whereApproval($query, strtoupper((string) $v)));

        $query->orderByDesc('tgl_ppb')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));
        $approval = PpbApprovalService::statusFor($page->getCollection()->pluck('no_ppb'));

        return response()->json([
            // Accurate's own PODET.REQID/REQSEQ chain, carried all the way through
            // to RI (APITMDET.POID/POSEQ) — which PO(s) this PPB was purchased
            // through and which RI(s) received against them. A PPB line can be
            // split across more than one PO/RI, hence the distinct joins.
            'data' => $page->getCollection()->map(function (Ppb $ppb) use ($approval) {
                // Accurate's chain, else (a STOCKWISE PPB) the PO made from it — not while it still waits for its vendor
                $poItems = $ppb->poItems->isNotEmpty() ? $ppb->poItems
                    : $ppb->approvedPoItems->filter(fn ($i) => $i->purchaseOrder && $i->purchaseOrder->status !== \App\Services\PpbApprovalService::WAITING)->values();
                $riLines = $poItems->pluck('riLines')->flatten();

                return (new PpbResource($ppb))->resolve() + [
                    'approval' => $approval[$ppb->no_ppb] ?? null,
                    'vendor' => $this->joinDistinct($poItems->map(fn ($i) => $i->vendor?->name ?? $i->purchaseOrder?->vendor?->name)),
                    'po_no' => $this->joinDistinct($poItems->pluck('purchaseOrder.number')),
                    'po_date' => $this->joinDistinct($poItems->pluck('purchaseOrder.date')->filter()->map(fn ($d) => $d->toDateString())),
                    'ri_no' => $this->joinDistinct($riLines->pluck('no_ri')),
                    'ri_date' => $this->joinDistinct($riLines->pluck('tgl_ri')->filter()->map(fn ($d) => $d->toDateString())),
                ];
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
                // for approvers: PPB lines still waiting for them (the banner), in this same request
                'approval_waiting' => self::waitingFor($request->user()),
            ],
        ]);
    }

    /** PPB lines waiting for this user's step (null = not an approver). */
    public static function waitingFor(?\App\Models\User $user): ?int
    {
        if (! $user || ! collect(['ppb.approve', 'ppb.approve_manager', 'ppb.approve_director'])->contains(fn ($p) => $user->hasPermission($p))) {
            return null;
        }
        $q = Ppb::query()->where(function ($w) use ($user) {
            $w->whereRaw('1 = 0');
            if ($user->hasPermission('ppb.approve')) {
                $w->orWhere(fn ($x) => tap($x, fn ($x) => self::whereApproval($x, 'MENUNGGU'))->whereNotNull('accurate_reqid'));
            }
            if ($user->hasPermission('ppb.approve_manager') || $user->hasPermission('ppb.approve_director')) {
                // the Direktur also sees what still waits for the Manager (bypass)
                $w->orWhere(fn ($x) => tap($x, fn ($x) => self::whereApproval($x, 'MENUNGGU'))->whereNull('accurate_reqid')->whereNotNull('created_by'));
            }
            if ($user->hasPermission('ppb.approve_director')) {
                $w->orWhere(fn ($x) => self::whereApproval($x, 'MENUNGGU_DIREKTUR'));
            }
        });

        return $q->count();
    }

    /** Narrow to PPBs whose approval (latest APPROVE / REJECT / APPROVE_MANAGER in ppb_approvals) is $status. */
    private static function whereApproval($query, string $status): void
    {
        $t = PpbApprovalService::TABLE;
        $actions = "'".implode("', '", PpbApprovalService::DECISIONS)."'";
        // no_ppb whose latest decision is $action
        $decided = fn (string $action) => \Illuminate\Support\Facades\DB::table("{$t} as a")->select('a.no_ppb')
            ->where('a.action', $action)
            ->whereRaw("a.id = (select max(b.id) from {$t} b where b.no_ppb = a.no_ppb and b.action in ({$actions}))");
        match ($status) {
            'DISETUJUI' => $query->whereIn('no_ppb', $decided('APPROVE')),
            'DITOLAK' => $query->whereIn('no_ppb', $decided('REJECT')),
            'MENUNGGU_DIREKTUR' => $query->whereIn('no_ppb', $decided('APPROVE_MANAGER')),
            'MENUNGGU' => $query->where(fn ($w) => $w->whereDate('tgl_ppb', '>=', PpbApprovalService::GO_LIVE)->orWhere(fn ($m) => $m->whereNull('accurate_reqid')->whereNotNull('created_by')))
                ->whereNotIn('no_ppb', \Illuminate\Support\Facades\DB::table($t)->select('no_ppb')->whereIn('action', PpbApprovalService::DECISIONS)),
            'PO_TANPA_APPROVAL' => $query->whereIn('no_ppb', \Illuminate\Support\Facades\DB::table($t)->select('no_ppb')->where('action', 'PO_TANPA_APPROVAL'))
                ->whereNotIn('no_ppb', $decided('APPROVE')),
            default => null,
        };
    }

    /** POST /api/ppb/{ppb}/approval {action: APPROVE|REJECT, note} — for the whole PPB document (its no_ppb). */
    public function decide(Request $request, Ppb $ppb, PpbApprovalService $approvals): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:APPROVE,REJECT,RETURN'], 'note' => ['nullable', 'string', 'max:1000']]);
        // the approver does not change quantities: RETURN, admin revises them
        \App\Support\ApprovalQuantities::refuseFromApprover($request->input('quantities'));
        abort_unless($ppb->no_ppb, 422, 'PPB ini tidak punya nomor.');
        $approvals->decide($ppb->no_ppb, $request->user(), $data['action'], $data['note'] ?? null, []);

        return $this->show($ppb);
    }

    /** POST /api/ppb/{id}/resubmit {quantities?, note?} — admin revised a PPB sent back: to the approval again. */
    public function resubmit(Request $request, Ppb $ppb, PpbApprovalService $approvals): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'quantities' => ['nullable', 'array'], 'quantities.*' => ['numeric']]);
        abort_unless($ppb->no_ppb, 422, 'PPB ini tidak punya nomor.');
        $approvals->resubmit($ppb->no_ppb, $request->user(), collect($data['quantities'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (float) $q])->all(), $data['note'] ?? null);

        return $this->show($ppb);
    }

    /** Comma-join of a distinct, non-empty collection — a PPB line can be purchased through more than one PO. */
    private function joinDistinct(\Illuminate\Support\Collection $values): ?string
    {
        $values = $values->filter()->unique()->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }

    /** Divisi options for the filter dropdown — a real, evolving list (Accurate department names), not a fixed enum. "NA"/"ATK" excluded — those come from the PPB numbering (PPB/NA/.., PPB/ATK/..), not a real division (see ppbDivisi() on the frontend). */
    public function divisiOptions(): JsonResponse
    {
        $options = Ppb::query()
            ->whereNotNull('divisi')
            ->whereNotIn('divisi', ['NA', 'ATK'])
            ->distinct()
            ->orderBy('divisi')
            ->pluck('divisi');

        return response()->json(['data' => $options]);
    }

    /** Vendor options for the filter: only vendors that have a PO raised from a PPB line. */
    public function vendorOptions(): JsonResponse
    {
        $options = \App\Models\Vendor::query()
            ->whereIn('id', \App\Models\PurchaseOrder::query()->select('vendor_id')
                ->whereNotNull('vendor_id')
                ->whereHas('items', fn ($i) => $i->whereNotNull('accurate_ppb_id')))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $options]);
    }

    /**
     * POST /api/ppb — manual PPB, for requests that never went through Accurate.
     * Unlike NPBG, no_ppb/tgl_ppb are typed by the user (not auto-generated) —
     * accurate_reqid/accurate_seq stay null, the "is the accurate id null?" marker
     * used elsewhere (NPBG, PO, Opname).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no_ppb' => ['required', 'string', 'max:60'],
            'tgl_ppb' => ['required', 'date'],
            'tgl_diperlukan' => ['nullable', 'date'],
            'divisi' => ['nullable', 'string', 'max:30'],
            'kode_barang' => ['nullable', 'string', 'max:60'],
            'deskripsi_barang' => ['required', 'string', 'max:400'],
            'kuantitas' => ['required', 'numeric', 'min:0.01'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'peminta' => ['nullable', 'string', 'max:150'],
            'catatan_baris' => ['nullable', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            // the user confirmed it is not the same PPB as a recent look-alike (DuplicateGuard)
            'allow_duplicate' => ['sometimes', 'boolean'],
        ]);
        $allowDuplicate = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['allow_duplicate']);

        // never the same PPB line twice (two admins, one request)
        $ppb = \App\Support\DuplicateGuard::locked('ppb', $data['kode_barang'] ?? $data['deskripsi_barang'], function () use ($data, $request, $allowDuplicate) {
            [$found, $hard] = \App\Support\DuplicateGuard::ppb($data);
            \App\Support\DuplicateGuard::refuse($found, $allowDuplicate, $hard);

            return Ppb::create($data + [
                'status' => 'OPEN',
                'line_status' => PpbLineStatus::of($data['kuantitas'], null, false),
                'is_amend' => false,
                'created_by' => $request->user()->id,
            ]);
        });
        app(PpbApprovalService::class)->scan();

        return response()->json(['data' => (new PpbResource($ppb))->resolve()], 201);
    }

    /**
     * GET /api/ppb/{ppb}/print — one printable PPB document, positioned to
     * match the real Accurate template (Template\PPB.frf, page 210×147mm) —
     * coordinates read out of that file's binary, see NpbgController::print().
     * Peminta and Tgl. Diperlukan are per-line columns here (same as the DB
     * schema), not header fields.
     */
    public function print(Request $request, Ppb $ppb, DocumentPrintRenderer $renderer): Response
    {
        $html = $renderer->render(...$this->printArgs($ppb, $request));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** GET …/print-pdf — the same document as a real PDF (data only), printed from a desktop PDF reader; see DocumentPdfWriter. */
    public function printPdf(Request $request, Ppb $ppb, DocumentPrintRenderer $renderer): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($ppb->no_ppb ?? 'dokumen')).'.pdf';

        return response($renderer->pdf(...$this->printArgs($ppb, $request)))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    private function printArgs(Ppb $ppb, Request $request): array
    {
        $lines = $ppb->no_ppb ? Ppb::where('no_ppb', $ppb->no_ppb)->orderBy('id')->get() : collect([$ppb]);
        $first = $lines->first();
        $fmtDate = fn ($d) => $d?->format('d/m/Y');

        return [
            'pageWidthMm' => 210,
            'pageHeightMm' => 147,
            'offsetXMm' => (float) $request->input('offset_x', 0),
            'offsetYMm' => (float) $request->input('offset_y', 0),
            'autoPageSize' => $request->string('page_size', 'exact')->value() === 'auto',
            'texts' => [
                ['x' => 28.6, 'y' => 6.3, 'w' => 72, 'text' => 'PT SURYA INTI GAS', 'size' => 9, 'bold' => true, 'static' => true],
                ['x' => 122.8, 'y' => 5.3, 'w' => 72, 'h' => 16.9, 'text' => "PERMINTAAN PEMBELIAN\nBARANG (PPB)", 'size' => 9, 'bold' => true, 'align' => 'center', 'static' => true],
                ['x' => 123.8, 'y' => 25.4, 'w' => 26.5, 'text' => 'Nomor PPB', 'bold' => true, 'static' => true],
                ['x' => 152.4, 'y' => 25.4, 'w' => 41.3, 'text' => $first->no_ppb],
                ['x' => 123.8, 'y' => 29.6, 'w' => 26.5, 'text' => 'Tanggal PPB', 'bold' => true, 'static' => true],
                ['x' => 152.4, 'y' => 29.6, 'w' => 41.3, 'text' => $fmtDate($first->tgl_ppb)],
                ['x' => 6.3, 'y' => 58.7, 'w' => 106.9, 'text' => 'Keterangan:', 'bold' => true, 'static' => true],
                ['x' => 6.3, 'y' => 62.6, 'w' => 106.9, 'h' => 14, 'text' => $first->keterangan],
            ],
            'table' => [
                'y' => 36.5,
                'rowHeight' => 6.9,
                'columns' => [
                    ['x' => 5.3, 'w' => 6.3, 'header' => 'No.', 'align' => 'center'],
                    ['x' => 11.6, 'w' => 21.2, 'header' => 'Kode Barang'],
                    ['x' => 32.8, 'w' => 56.1, 'header' => 'Deskripsi Barang'],
                    ['x' => 88.9, 'w' => 11.6, 'header' => 'Kts', 'align' => 'right'],
                    ['x' => 100.5, 'w' => 11.6, 'header' => 'Satuan'],
                    ['x' => 112.2, 'w' => 13.8, 'header' => 'Peminta'],
                    ['x' => 125.9, 'w' => 24.3, 'header' => 'Tgl. Diperlukan'],
                    ['x' => 150.3, 'w' => 44.5, 'header' => 'Catatan'],
                ],
            ],
            'tableRows' => $lines->values()->map(fn ($l, $i) => [
                $i + 1, $l->kode_barang, $l->deskripsi_barang, $l->kuantitas, $l->satuan,
                $l->peminta, $fmtDate($l->tgl_diperlukan), $l->catatan_baris,
            ])->all(),
            'signatures' => [
                'y' => 58.7,
                'lineY' => 74.6,
                'roleY' => 75.4,
                'columns' => [
                    ['x' => 115.4, 'w' => 18.0, 'action' => 'Disiapkan Oleh', 'role' => 'Adm. Gudang'],
                    ['x' => 135.5, 'w' => 18.0, 'action' => 'Diperiksa Oleh', 'role' => 'Manager'],
                    ['x' => 155.6, 'w' => 18.0, 'action' => 'Disetujui Oleh', 'role' => 'Purchasing'],
                    ['x' => 175.7, 'w' => 18.0, 'action' => 'Diketahui Oleh', 'role' => 'Direktur'],
                ],
            ],
        ];
    }

    public function show(Ppb $ppb): JsonResponse
    {
        $ppb->load('poItems:id,accurate_ppb_id,purchase_order_id,qty,qty_received', 'poItems.purchaseOrder:id,number');

        return response()->json(['data' => (new PpbResource($ppb))->resolve() + [
            // Accurate's own PODET.REQID/REQSEQ chain — which PO lines were raised from this PPB line.
            'purchased_via' => $ppb->poItems->map(fn ($i) => [
                'purchase_order_id' => $i->purchase_order_id, 'number' => $i->purchaseOrder?->number,
                'qty' => $i->qty, 'qty_received' => $i->qty_received,
            ]),
            // approval before PO — for the whole PPB document — and its log
            'approval' => $approval = ($ppb->no_ppb ? (PpbApprovalService::statusFor(collect([$ppb->no_ppb]))[$ppb->no_ppb] ?? null) : null),
            // can the viewer act on it now (their step of a STOCKWISE PPB, or Accurate's one-step approval)
            'can_decide' => $approval && request()->user() ? PpbApprovalService::canDecide(request()->user(), $approval) : false,
            // sent back: admin revises it and sends it to the approval again
            'can_resubmit' => ($approval['status'] ?? null) === 'DIKEMBALIKAN' && (bool) request()->user()?->hasPermission('ppb.create'),
            'approval_log' => $ppb->no_ppb ? PpbApprovalService::log($ppb->no_ppb) : [],
            // the "Menunggu PO" entry the approval put in the PO table (until Accurate's PO replaces it)
            'approval_po' => $ppb->no_ppb ? PpbApprovalService::poEntry($ppb->no_ppb)?->only('id', 'number') : null,
            'document_lines' => $ppb->no_ppb ? Ppb::where('no_ppb', $ppb->no_ppb)->count() : 1,
            // every line of the PPB document — the approval covers all of them, so the approver sees them all
            'document_items' => $ppb->no_ppb ? Ppb::where('no_ppb', $ppb->no_ppb)->orderBy('accurate_seq')->orderBy('id')
                ->get(['id', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'peminta', 'catatan_baris']) : [],
        ]]);
    }
}
