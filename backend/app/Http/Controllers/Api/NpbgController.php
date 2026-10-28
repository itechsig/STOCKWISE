<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NpbgResource;
use App\Models\Npbg;
use App\Services\DocumentNumberService;
use App\Services\Export\DocumentPrintRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NPBG — Accurate ARINV/ARINVDET mirror. Mostly read-only (rows come from Sync
 * Accurate, App\Services\Accurate\AccurateSyncService; update() only ever touches
 * the Stockwise-owned enrichment fields, Npbg::STOCKWISE_OWNED_FIELDS) — but
 * store() also allows creating a row manually for goods-out that never went
 * through Accurate (see Npbg model docblock for the accurate_* = null convention).
 */
class NpbgController extends Controller
{
    public function __construct(private DocumentNumberService $numbers)
    {
    }

    /** GET /api/npbg/klasifikasi — the values in use (Accurate SHIPMENT names, "Klasifikasi [Ship Via]"), for the filter. */
    public function klasifikasiOptions(): JsonResponse
    {
        return response()->json(['data' => Npbg::query()->whereNotNull('klasifikasi')->distinct()->orderBy('klasifikasi')->pluck('klasifikasi')]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Npbg::query()->select('npbg.*')->withCount('verifications')->with('latestVerification')
            ->addSelect(['kembali_bekas_status' => self::kembaliBekasStatus()]);

        if ($s = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($s) {
                $q->where('no_npbg', 'like', "%{$s}%")
                    ->orWhere('kode_barang', 'like', "%{$s}%")
                    ->orWhereLoose('deskripsi_barang', $s)
                    ->orWhere('peminta', 'like', "%{$s}%")
                    ->orWhere('lokasi_rak', 'like', "%{$s}%")
                    ->orWhere('no_seri', 'like', "%{$s}%")
                    ->orWhere('divisi', 'like', "%{$s}%")
                    ->orWhere('pelanggan', 'like', "%{$s}%")
                    ->orWhereLoose('keterangan', $s);
            });
        }

        $request->whenFilled('tipe_npbg', fn ($v) => $query->where('tipe_npbg', $v));
        $request->whenFilled('klasifikasi', fn ($v) => $query->where('klasifikasi', $v));
        $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
        // ?jenis_pengeluaran= = the old name of klasifikasi (kept for pages still open on the previous version)
        $request->whenFilled('jenis_pengeluaran', fn ($v) => $query->where('klasifikasi', $v));
        $request->whenFilled('pelanggan', fn ($v) => $query->where('pelanggan', $v));
        // ?kembali_bekas=1 / 0 — lines ticked / not ticked "Kembali Bekas"
        $request->whenFilled('kembali_bekas', fn ($v) => $request->boolean('kembali_bekas')
            ? $query->whereExists(self::kembaliBekasRows())
            : $query->whereNotExists(self::kembaliBekasRows()));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_npbg', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_npbg', '<=', $v));

        $query->orderByDesc('tgl_npbg')->orderByDesc('id');

        $page = $query->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => NpbgResource::collection($page->getCollection())->resolve(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * POST /api/npbg — manual NPBG, for goods-out that never went through Accurate.
     * Numbered distinctly ("NPBG/MANUAL/26/X/001") so it can never collide with a
     * real Accurate invoice number; accurate_arinvoice_id/accurate_seq stay null,
     * the same "is the accurate id null?" marker used elsewhere (PO, Opname).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pelanggan' => ['required', 'string', 'max:200'],
            'alamat_kirim' => ['nullable', 'string', 'max:255'],
            'divisi' => ['nullable', 'string', 'max:200'],
            'tipe_npbg' => ['required', 'in:Penjualan,Non Penjualan'],
            'klasifikasi' => ['nullable', 'string', 'max:50'],
            'kode_barang' => ['nullable', 'string', 'max:60'],
            'deskripsi_barang' => ['required', 'string', 'max:400'],
            'kuantitas' => ['required', 'numeric', 'min:0.01'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'no_seri' => ['nullable', 'string'],
            'peminta' => ['nullable', 'string', 'max:150'],
            'deskripsi' => ['nullable', 'string', 'max:500'],
            'keterangan' => ['nullable', 'string'],
            'tgl_npbg' => ['nullable', 'date'],
            // "Kembali bekas" ticked in the Tambah NPBG form -> straight into Tracking Pengembalian Bekas
            'kembali_bekas' => ['sometimes', 'boolean'],
            // the user confirmed it is not the same NPBG as a recent look-alike (DuplicateGuard)
            'allow_duplicate' => ['sometimes', 'boolean'],
        ]);
        $kembaliBekas = (bool) ($data['kembali_bekas'] ?? false);
        $allowDuplicate = (bool) ($data['allow_duplicate'] ?? false);
        unset($data['kembali_bekas'], $data['allow_duplicate']);
        abort_if($kembaliBekas && ! $request->user()->hasPermission('used_return.update'), 403,
            'Tidak punya akses Pengembalian Bekas — hilangkan centang "Kembali bekas".');

        $data['tgl_npbg'] ??= now()->toDateString();

        // never the same NPBG twice (two admins, one request): checked and saved under one lock per item
        $npbg = \App\Support\DuplicateGuard::locked('npbg', $data['kode_barang'] ?? $data['deskripsi_barang'], fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($data, $request, $kembaliBekas, $allowDuplicate) {
            \App\Support\DuplicateGuard::refuse(\App\Support\DuplicateGuard::npbg($data), $allowDuplicate);
            $npbg = Npbg::create($data + [
                'no_npbg' => $this->numbers->next('NPBG', 'MANUAL'),
                'unit_ratio' => 1,
                'created_by' => $request->user()->id,
            ]);
            if ($kembaliBekas) {
                self::trackKembaliBekas($npbg, $request->user()->id);
            }

            return $npbg;
        }));
        $npbg->setAttribute('kembali_bekas_status', self::kembaliBekasStatus($npbg->id)->value('status'));
        // made here, not from a Request Barang: Manager → Direktur approve it before the goods leave the stock
        app(\App\Services\NpbgApprovalService::class)->submitted($npbg, $request->user());

        return response()->json(['data' => new NpbgResource($npbg)], 201);
    }

    /**
     * GET /api/npbg/{npbg}/print — one printable NPBG document, positioned to
     * match the real Accurate template (Template\NPBG.frf, page 210×147mm) —
     * every coordinate below was read out of that file's binary component
     * records (name + Left/Top/Width/Height in 1/96" units), not guessed, since
     * this prints onto pre-printed custom stationery. One Accurate NPBG can
     * have several line rows sharing the same no_npbg — printed together as
     * one document's item table, not just the single clicked row.
     */
    public function print(Request $request, Npbg $npbg, DocumentPrintRenderer $renderer): Response
    {
        $html = $renderer->render(...$this->printArgs($npbg, $request));

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** GET …/print-pdf — the same document as a real PDF (data only), printed from a desktop PDF reader; see DocumentPdfWriter. */
    public function printPdf(Request $request, Npbg $npbg, DocumentPrintRenderer $renderer): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($npbg->no_npbg ?? 'dokumen')).'.pdf';

        return response($renderer->pdf(...$this->printArgs($npbg, $request)))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** @return array{pageWidthMm:float,pageHeightMm:float,texts:array,table:array,tableRows:array,signatures:array,offsetXMm:float,offsetYMm:float,autoPageSize:bool} */
    private function printArgs(Npbg $npbg, Request $request): array
    {
        $lines = $npbg->no_npbg ? Npbg::where('no_npbg', $npbg->no_npbg)->orderBy('id')->get() : collect([$npbg]);
        $first = $lines->first();
        $fmtDate = fn ($d) => $d?->format('d/m/Y');

        return [
            'pageWidthMm' => 210,
            'pageHeightMm' => 147,
            'offsetXMm' => (float) $request->input('offset_x', 0),
            'offsetYMm' => (float) $request->input('offset_y', 0),
            'autoPageSize' => $request->string('page_size', 'exact')->value() === 'auto',
            'texts' => [
                ['x' => 27.5, 'y' => 5.8, 'w' => 78.3, 'text' => 'PT SURYA INTI GAS', 'size' => 9, 'bold' => true, 'static' => true],
                ['x' => 122.8, 'y' => 5.3, 'w' => 72, 'h' => 16.9, 'text' => "NOTA PENGELUARAN\nBARANG GUDANG (NPBG)", 'size' => 9, 'bold' => true, 'align' => 'center', 'static' => true],
                ['x' => 122.8, 'y' => 25.4, 'w' => 36, 'text' => 'No. NPBG', 'bold' => true, 'static' => true],
                ['x' => 158.8, 'y' => 25.4, 'w' => 36, 'text' => $first->no_npbg],
                ['x' => 122.8, 'y' => 29.6, 'w' => 36, 'text' => 'Tgl. NPBG', 'bold' => true, 'static' => true],
                ['x' => 158.8, 'y' => 29.6, 'w' => 36, 'text' => $fmtDate($first->tgl_npbg)],
                ['x' => 122.8, 'y' => 36.0, 'w' => 19.1, 'text' => 'Tipe NPBG', 'bold' => true, 'static' => true],
                ['x' => 141.8, 'y' => 36.0, 'w' => 52.9, 'text' => ': '.$first->tipe_npbg],
                ['x' => 122.8, 'y' => 40.2, 'w' => 19.1, 'text' => 'Klasifikasi', 'bold' => true, 'static' => true],
                ['x' => 141.8, 'y' => 40.2, 'w' => 52.9, 'text' => ': '.$first->klasifikasi],
                ['x' => 9.5, 'y' => 40.2, 'w' => 24.3, 'text' => 'Divisi Peminta :', 'bold' => true, 'static' => true],
                ['x' => 33.9, 'y' => 40.2, 'w' => 51.9, 'text' => $first->divisi],
                ['x' => 6.3, 'y' => 22.2, 'w' => 18, 'text' => 'Pelanggan :', 'bold' => true, 'static' => true],
                ['x' => 7.4, 'y' => 27.5, 'w' => 54, 'h' => 4.2, 'text' => $first->pelanggan],
                ['x' => 63.5, 'y' => 22.2, 'w' => 22.2, 'text' => 'Alamat Kirim :', 'bold' => true, 'static' => true],
                ['x' => 64.6, 'y' => 27.5, 'w' => 54, 'h' => 12.7, 'text' => $first->alamat_kirim],
                ['x' => 6.3, 'y' => 68.3, 'w' => 80.4, 'text' => 'Keterangan:', 'bold' => true, 'static' => true],
                ['x' => 6.3, 'y' => 72.2, 'w' => 80.4, 'h' => 13, 'text' => $first->keterangan],
            ],
            'table' => [
                'y' => 46.0,
                'rowHeight' => 6.9,
                'columns' => [
                    ['x' => 5.3, 'w' => 7.4, 'header' => 'No.', 'align' => 'center'],
                    ['x' => 12.7, 'w' => 22.2, 'header' => 'Kode Barang'],
                    ['x' => 34.9, 'w' => 52.9, 'header' => 'Deskripsi Barang'],
                    ['x' => 87.8, 'w' => 10.6, 'header' => 'Kts', 'align' => 'right'],
                    ['x' => 98.4, 'w' => 11.6, 'header' => 'Satuan'],
                    ['x' => 110.1, 'w' => 22.2, 'header' => 'No. Seri'],
                    ['x' => 132.3, 'w' => 15.9, 'header' => 'Peminta'],
                    ['x' => 148.2, 'w' => 46.6, 'header' => 'Catatan'],
                ],
            ],
            'tableRows' => $lines->values()->map(fn ($l, $i) => [
                $i + 1, $l->kode_barang, $l->deskripsi_barang, $l->kuantitas, $l->satuan, $l->no_seri, $l->peminta, $l->deskripsi,
            ])->all(),
            'signatures' => [
                'y' => 67.2,
                'lineY' => 82.2,
                'roleY' => 83.1,
                'columns' => [
                    ['x' => 88.9, 'w' => 21.2, 'action' => 'Disiapkan Oleh', 'role' => 'Lap. Gudang'],
                    ['x' => 110.1, 'w' => 21.2, 'action' => 'Diperiksa Oleh', 'role' => 'Adm. Gudang'],
                    ['x' => 131.2, 'w' => 21.2, 'action' => 'Diketahui Oleh', 'role' => 'Manager'],
                    ['x' => 152.4, 'w' => 21.2, 'action' => 'Disetujui Oleh', 'role' => 'Direktur'],
                    ['x' => 173.6, 'w' => 21.2, 'action' => 'Diterima Oleh', 'role' => 'Nama:'],
                ],
            ],
        ];
    }

    /** POST /api/npbg/{npbg}/approval {action: APPROVE|REJECT, note} — the Manager / Direktur step of a manual NPBG. */
    public function decide(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate(['action' => ['required', 'in:APPROVE,REJECT,RETURN'], 'note' => ['nullable', 'string', 'max:1000']]);
        // the approver does not change quantities: RETURN, admin revises them
        \App\Support\ApprovalQuantities::refuseFromApprover($request->input('quantities'));
        app(\App\Services\NpbgApprovalService::class)->decide($npbg, $request->user(), $data['action'], $data['note'] ?? null, []);

        return $this->show($npbg->refresh());
    }

    /** POST /api/npbg/{id}/resubmit {quantities?, note?} — admin revised a manual NPBG sent back: to the approval again. */
    public function resubmit(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000'], 'quantities' => ['nullable', 'array'], 'quantities.*' => ['numeric']]);
        app(\App\Services\NpbgApprovalService::class)->resubmit($npbg, $request->user(),
            collect($data['quantities'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (float) $q])->all(), $data['note'] ?? null);

        return $this->show($npbg->refresh());
    }

    public function show(Npbg $npbg): NpbgResource
    {
        $npbg->setAttribute('kembali_bekas_status', self::kembaliBekasStatus($npbg->id)->value('status'));
        $approval = \App\Services\NpbgApprovalService::statusFor(collect([$npbg->no_npbg]))[$npbg->no_npbg] ?? null;
        if ($approval) {
            $npbg->setAttribute('approval', $approval);
            $npbg->setAttribute('approval_log', \App\Services\NpbgApprovalService::log($npbg->no_npbg));
            $npbg->setAttribute('can_decide', request()->user() ? \App\Services\NpbgApprovalService::canDecide(request()->user(), $approval) : false);
            // sent back: admin revises it and sends it to the approval again
            $npbg->setAttribute('can_resubmit', $approval['status'] === 'DIKEMBALIKAN' && (bool) request()->user()?->hasPermission('npbg.create'));
        }

        return new NpbgResource($npbg->loadCount('verifications')->load('latestVerification'));
    }

    /**
     * PUT /api/npbg/{npbg}/kembali-bekas {checked} — the "Kembali Bekas" tick of
     * one NPBG line. Ticked = the line goes into Tracking Pengembalian Bekas,
     * sheet "Spare Part Lain" (one item per row, PENDING until the RI back comes
     * in); the tick IS that linked row, so it survives Sync Accurate (which owns
     * the NPBG row itself). Unticking removes the row this tick created — only
     * while nothing has been recorded on it yet (RI back, Foto Terima, photos).
     */
    public function kembaliBekas(Request $request, Npbg $npbg): JsonResponse
    {
        $checked = $request->validate(['checked' => ['required', 'boolean']])['checked'];
        $table = \App\Services\Tracking\UsedReturnSheets::OTHER;

        \Illuminate\Support\Facades\DB::transaction(function () use ($checked, $npbg, $table, $request) {
            $rows = \Illuminate\Support\Facades\DB::table($table)->where('npbg_id', $npbg->id)->lockForUpdate()->get();
            if ($checked) {
                if ($rows->isEmpty()) {
                    self::trackKembaliBekas($npbg, $request->user()->id);
                }

                return;
            }
            $photos = app(\App\Services\Tracking\TrackingPhotoService::class);
            foreach ($rows as $r) {
                $used = trim((string) $r->ri_no) !== '' || trim((string) $r->in_file_name) !== ''
                    || \App\Models\TrackingPhoto::where(['entity' => \App\Services\Tracking\UsedReturnSheets::PHOTO_ENTITY, 'entity_id' => $r->id])->exists();
                abort_if($r->source !== 'NPBG' || $used, 422,
                    'Baris ini sudah punya data di Tracking Pengembalian Bekas (RI kembali / foto / dibuat manual) — ubah atau hapus dari halaman Pengembalian Bekas.');
            }
            \Illuminate\Support\Facades\DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
        });

        return response()->json(['data' => [
            'kembali_bekas' => $checked,
            'kembali_bekas_status' => self::kembaliBekasStatus($npbg->id)->value('status'),
        ]]);
    }

    /** Adds one NPBG line to Tracking Pengembalian Bekas, sheet "Spare Part Lain" (PENDING until the RI back). */
    private static function trackKembaliBekas(Npbg $npbg, int $userId): void
    {
        $table = \App\Services\Tracking\UsedReturnSheets::OTHER;
        // Item No = the line's position in its NPBG document (same order as the printed NPBG)
        $itemNo = $npbg->no_npbg
            ? Npbg::where('no_npbg', $npbg->no_npbg)->where('id', '<=', $npbg->id)->count()
            : 1;
        $id = \Illuminate\Support\Facades\DB::table($table)->insertGetId([
            'sheet_no' => 1 + (int) \Illuminate\Support\Facades\DB::table($table)->max('sheet_no'),
            'npbg_date' => $npbg->tgl_npbg?->toDateString(),
            'npbg_no' => $npbg->no_npbg,
            'npbg_id' => $npbg->id,
            'description' => mb_substr((string) $npbg->deskripsi_barang, 0, 400) ?: null,
            'qty' => $npbg->kuantitas,
            'unit' => $npbg->satuan ? mb_substr($npbg->satuan, 0, 20) : null,
            'item_no' => $itemNo,
            'status' => 'PENDING',
            'source' => 'NPBG',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Services\Tracking\UsedReturnSheets::recomputeOther($id);
    }

    /** Rows of Tracking Pengembalian Bekas "Spare Part Lain" linked to the outer npbg row (for whereExists). */
    private static function kembaliBekasRows(): \Closure
    {
        return fn ($q) => $q->from(\App\Services\Tracking\UsedReturnSheets::OTHER)->whereColumn('npbg_id', 'npbg.id');
    }

    /** Status (PENDING / CLEAR / …) of the line's "Kembali Bekas" tracking row, null = not ticked. */
    private static function kembaliBekasStatus(?int $npbgId = null): \Illuminate\Database\Query\Builder
    {
        $q = \Illuminate\Support\Facades\DB::table(\App\Services\Tracking\UsedReturnSheets::OTHER)->select('status')->orderBy('id')->limit(1);

        return $npbgId === null ? $q->whereColumn('npbg_id', 'npbg.id') : $q->where('npbg_id', $npbgId);
    }

    public function update(Request $request, Npbg $npbg): NpbgResource
    {
        $data = $request->validate([
            'tipe_npbg' => ['sometimes', 'nullable', 'in:Penjualan,Non Penjualan'],
            'deskripsi' => ['sometimes', 'nullable', 'string', 'max:500'],
            'nama_proyek' => ['sometimes', 'nullable', 'string', 'max:200'],
            'dikeluarkan_oleh' => ['sometimes', 'nullable', 'string', 'max:150'],
        ]);

        // Belt-and-braces: only Stockwise-owned columns can ever reach save(),
        // even if a future field gets added to the validation rules above.
        // Tipe NPBG follows Accurate's Terms; only a manual NPBG (no Accurate id) sets its own
        $owned = $npbg->accurate_arinvoice_id === null ? [...Npbg::STOCKWISE_OWNED_FIELDS, 'tipe_npbg'] : Npbg::STOCKWISE_OWNED_FIELDS;
        $npbg->fill(array_intersect_key($data, array_flip($owned)))->save();

        return new NpbgResource($npbg);
    }
}
