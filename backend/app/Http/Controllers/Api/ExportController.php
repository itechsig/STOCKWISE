<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\PurchaseOrderItem;
use App\Models\Ri;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StppTransaction;
use App\Models\TyreChange;
use App\Models\UsedReturnItem;
use App\Services\Export\DatasetExporter;
use App\Services\Inventory\InventoryAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PHASE 10 — laporan & export (Excel / CSV / PDF-print). brief §AG.
 * GET /api/export/{dataset}?format=xlsx|csv|pdf
 */
class ExportController extends Controller
{
    private const DATASETS = [
        'items' => 'report.items',
        'item-history' => 'item.view',
        'inventory' => 'report.inventory',
        'inventory_analysis' => 'inventory.view_analysis',
        'npbg' => 'report.npbg',
        'ppb' => 'report.ppb',
        'purchase-orders' => 'po.view',
        'ri' => 'report.ri',
        'stock-opnames' => 'report.opname',
        'stock-opname-lines' => 'report.opname',
        'stock-movements' => 'report.stock_movement',
        'lend' => 'lend.view',
        'borrow' => 'borrow.view',
        'stpp' => 'stpp.view',
        'tyre' => 'tyre.view',
        'maintenance' => 'maintenance.view',
        'manufacturing' => 'manufacturing.view',
        'used-returns' => 'used_return.view',
    ];

    /**
     * key => [label, value getter] — kolom Analisis Inventory, dalam urutan tampil.
     * Dipakai juga oleh frontend (lihat INVENTORY_ANALYSIS_COLUMNS di InventoryHealthPage.tsx)
     * untuk daftar hide-kolom; kuncinya harus sama persis di kedua sisi. (Metode, bukan
     * const, karena class const tidak boleh berisi closure.)
     */
    private static function inventoryAnalysisColumns(): array
    {
        return [
            'code' => ['Kode', fn ($r) => $r->item_code],
            'description' => ['Deskripsi', fn ($r) => $r->item_description],
            'available' => ['Tersedia', fn ($r) => $r->stock_known ? $r->available : 'UNKNOWN'],
            'safety_stock' => ['Safety Stock', fn ($r) => $r->safety_stock],
            'avg_usage_1m' => ['1 Bulan', fn ($r) => $r->avg_usage_1m !== null ? (int) round($r->avg_usage_1m) : null],
            'avg_usage_3m' => ['3 Bulan', fn ($r) => $r->avg_usage_3m !== null ? (int) round($r->avg_usage_3m) : null],
            'avg_usage_6m' => ['6 Bulan', fn ($r) => $r->avg_usage_6m !== null ? (int) round($r->avg_usage_6m) : null],
            'avg_usage_12m' => ['12 Bulan', fn ($r) => $r->avg_usage_12m !== null ? (int) round($r->avg_usage_12m) : null],
            'min_pr' => ['MIN PR', fn ($r) => $r->min_pr],
            'selisih' => ['Selisih', fn ($r) => $r->selisih],
            'deficit' => ['Defisit', fn ($r) => $r->deficit],
            'lead_time' => ['Lead Time', fn ($r) => $r->lead_time_days],
            'priority_score' => ['Skor Prioritas', fn ($r) => $r->priority_score],
            'status' => ['Status', fn ($r) => $r->status],
            'priority_level' => ['Prioritas', fn ($r) => $r->priority_level],
            'recommendation' => ['Rekomendasi', fn ($r) => $r->recommendation],
        ];
    }

    /** Comma-join of a distinct, non-empty collection — a line can span several PPB/RI/PO documents. */
    private static function joinDistinct(\Illuminate\Support\Collection $values): ?string
    {
        $values = $values->filter()->unique()->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }

    /**
     * key => list of [label, value getter] — same keys as the on-screen Column
     * array in PoListPage.tsx, in the same order. One on-screen column can
     * expand to several export columns (e.g. "ppb" -> Nomor PPB + Tanggal PPB),
     * which is how hiding "PPB" on screen hides both from the export too.
     */
    private static function purchaseOrderColumns(): array
    {
        return [
            'number' => [['Nomor PO', fn ($l) => $l->purchaseOrder->number], ['Tanggal PO', fn ($l) => $l->purchaseOrder->date?->toDateString()]],
            'vendor' => [['Vendor', fn ($l) => $l->purchaseOrder->vendor?->name]],
            'barang' => [['Kode Barang', fn ($l) => $l->item?->code], ['Deskripsi', fn ($l) => $l->description_raw ?: $l->item?->description]],
            'qty' => [['Qty', fn ($l) => $l->qty], ['Satuan', fn ($l) => $l->unit?->code]],
            'status' => [['Status', fn ($l) => $l->line_status]],
            'ppb' => [['Nomor PPB', fn ($l) => $l->accuratePpb?->no_ppb], ['Tanggal PPB', fn ($l) => $l->accuratePpb?->tgl_ppb?->toDateString()]],
            'ri' => [
                ['Nomor RI', fn ($l) => self::joinDistinct($l->riLines->pluck('no_ri'))],
                ['Tanggal RI', fn ($l) => self::joinDistinct($l->riLines->pluck('tgl_ri')->filter()->map(fn ($d) => $d->toDateString()))],
            ],
            'divisi' => [['Divisi', fn ($l) => $l->accuratePpb?->divisi]],
            'keterangan' => [['Keterangan', fn ($l) => $l->purchaseOrder->notes]],
        ];
    }

    /** Same idea as purchaseOrderColumns() — keys match PpbListPage.tsx's Column array. */
    private static function ppbColumns(): array
    {
        return [
            'no_ppb' => [['No PPB', fn ($p) => $p->no_ppb], ['Tgl PPB', fn ($p) => $p->tgl_ppb?->toDateString()]],
            'status' => [['Status', fn ($p) => trim(($p->line_status ?? '-').($p->is_amend ? ' (Amend)' : ''))]],
            'divisi' => [['Divisi', fn ($p) => $p->divisi]],
            'kode' => [['Kode Barang', fn ($p) => $p->kode_barang]],
            'barang' => [['Deskripsi Barang', fn ($p) => $p->deskripsi_barang]],
            'qty' => [['Kuantitas', fn ($p) => $p->kuantitas], ['Satuan', fn ($p) => $p->satuan]],
            'diterima' => [['Qty Dipesan', fn ($p) => $p->qty_dipesan], ['Qty Diterima', fn ($p) => $p->qty_diterima]],
            'peminta' => [['Peminta', fn ($p) => $p->peminta]],
            'vendor' => [['Vendor', fn ($p) => self::joinDistinct($p->poItems->pluck('purchaseOrder.vendor.name'))]],
            'po' => [
                ['Nomor PO', fn ($p) => self::joinDistinct($p->poItems->pluck('purchaseOrder.number'))],
                ['Tanggal PO', fn ($p) => self::joinDistinct($p->poItems->pluck('purchaseOrder.date')->filter()->map(fn ($d) => $d->toDateString()))],
            ],
            'ri' => [
                ['Nomor RI', fn ($p) => self::joinDistinct($p->poItems->pluck('riLines')->flatten()->pluck('no_ri'))],
                ['Tanggal RI', fn ($p) => self::joinDistinct($p->poItems->pluck('riLines')->flatten()->pluck('tgl_ri')->filter()->map(fn ($d) => $d->toDateString()))],
            ],
            'catatan' => [['Keterangan', fn ($p) => $p->keterangan], ['Catatan Baris', fn ($p) => $p->catatan_baris]],
        ];
    }

    /** Same idea as purchaseOrderColumns() — keys match RiListPage.tsx's Column array. */
    private static function riColumns(): array
    {
        return [
            'no_ri' => [['No RI', fn ($r) => $r->no_ri], ['Tgl RI', fn ($r) => $r->tgl_ri?->toDateString()]],
            'sj' => [['No Surat Jalan', fn ($r) => $r->no_surat_jalan]],
            'divisi' => [['Divisi', fn ($r) => $r->divisi]],
            'vendor' => [['Vendor', fn ($r) => $r->vendor]],
            'barang' => [['Kode Barang', fn ($r) => $r->kode_barang], ['Deskripsi Barang', fn ($r) => $r->deskripsi_barang]],
            'qty' => [['Kuantitas', fn ($r) => $r->kuantitas], ['Satuan', fn ($r) => $r->satuan]],
            'harga' => [['Harga Satuan', fn ($r) => $r->harga_satuan]],
            'ppb' => [['No PPB', fn ($r) => $r->no_ppb], ['Tgl PPB', fn ($r) => $r->poItem?->accuratePpb?->tgl_ppb?->toDateString()]],
            'po' => [['No PO', fn ($r) => $r->poItem?->purchaseOrder?->number], ['Tgl PO', fn ($r) => $r->poItem?->purchaseOrder?->date?->toDateString()]],
            'pemeriksa' => [['Pemeriksa', fn ($r) => $r->pemeriksa]],
            'lokasi' => [['Lokasi Gudang', fn ($r) => $r->lokasi_gudang], ['Lokasi Rak', fn ($r) => $r->lokasi_rak]],
            'seri' => [['No. Seri', fn ($r) => $r->no_seri]],
            'keterangan' => [['Keterangan', fn ($r) => $r->keterangan]],
        ];
    }

    /** ?columns=key1,key2,... (keys of one of the *Columns() maps above) -> [keys in declared order] — empty/unmatched = every column. */
    private static function requestedColumnKeys(Request $request, array $columnsMap): array
    {
        $requested = array_filter(array_map('trim', explode(',', (string) $request->string('columns'))));
        $keys = $requested !== []
            ? array_values(array_intersect(array_keys($columnsMap), $requested))
            : array_keys($columnsMap);

        return $keys === [] ? array_keys($columnsMap) : $keys;
    }

    public function __invoke(Request $request, string $dataset, DatasetExporter $exporter, InventoryAnalyzer $analyzer): StreamedResponse
    {
        abort_unless(isset(self::DATASETS[$dataset]), 404);
        abort_unless($request->user()->hasPermission(self::DATASETS[$dataset]), 403);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv', 'pdf'], true)) {
            throw ValidationException::withMessages(['format' => ['Format harus xlsx, csv, atau pdf.']]);
        }
        abort_unless($request->user()->hasPermission("export.{$this->exportPerm($format)}"), 403);

        [$title, $headers, $rows] = $this->build($dataset, $request, $analyzer);
        $filename = 'stockwise-'.$dataset.'-'.now()->format('Ymd-His');

        return $exporter->download($format, $filename, $headers, $rows, $title);
    }

    private function exportPerm(string $format): string
    {
        return $format === 'pdf' ? 'pdf' : ($format === 'csv' ? 'csv' : 'excel');
    }

    /** @return array{0:string,1:list<string>,2:iterable} */
    private function build(string $dataset, Request $request, InventoryAnalyzer $analyzer): array
    {
        return match ($dataset) {
            // Mengikuti filter yang sedang aktif di halaman Master Barang
            // (search, kategori Accurate anak 1/2/3, UOM, dll) — lihat
            // Item::filtered(), sumber tunggal yang sama dipakai ItemController::index().
            'items' => [
                'Master Barang',
                [
                    'Kode Barang', 'Kategori Induk', 'Kategori Anak 1', 'Kategori Anak 2', 'Kategori Anak 3',
                    'Deskripsi Barang', 'UOM', 'QTY', 'Forecast', 'Perlu Blueprint?', 'Nama Alias',
                    'Letak Gudang', 'Letak Rak', 'Blueprint IMG', 'Blueprint Detail PDF',
                    'Lead Time', 'Safety Stock', 'MIN PR',
                ],
                Item::filtered($request)
                    ->with('unit:id,code', 'defaultWarehouse:id,code', 'defaultLocation:id,code', 'effectiveSafetyStock:id,item_id,safety_stock,min_pr')
                    ->orderBy('items.code')
                    ->lazy()
                    ->map(fn (Item $i) => [
                        $i->code,
                        $i->accurate_category_induk,
                        $i->accurate_category_anak_1, $i->accurate_category_anak_2, $i->accurate_category_anak_3,
                        $i->description, $i->unit?->code, $i->accurate_qty_onhand,
                        (float) ($i->accurate_qty_onhand ?? 0) + (float) ($i->accurate_qty_onorder ?? 0),
                        $i->needs_blueprint ? 'Ya' : 'Tidak', 'Tidak',
                        $i->defaultWarehouse?->code, $i->defaultLocation?->code,
                        $i->blueprint_img_path, $i->blueprint_pdf_path,
                        $i->lead_time_days, $i->effectiveSafetyStock?->safety_stock, $i->effectiveSafetyStock?->min_pr,
                    ]),
            ],
            // Histori satu barang (MASUK dari RI/vendor, KELUAR dari NPBG/pelanggan
            // atau nopol, ADJUST dari stock opname) — logika gabung+urutnya sama
            // seperti ItemController::history(), lihat komentar di sana.
            'item-history' => (function () use ($request) {
                $item = Item::findOrFail($request->integer('item_id'));
                $code = $item->code;

                $masuk = Ri::where('kode_barang', $code)
                    ->select(['no_ri', 'tgl_ri', 'vendor', 'kuantitas', 'satuan', 'no_seri'])
                    ->get()
                    ->map(fn ($r) => [
                        'type' => 'MASUK', 'date' => $r->tgl_ri, 'doc_no' => $r->no_ri,
                        'counterparty_label' => 'Vendor', 'counterparty' => $r->vendor,
                        'qty' => $r->kuantitas, 'satuan' => $r->satuan, 'no_seri' => $r->no_seri, 'note' => null,
                    ]);

                $npbgRows = Npbg::where('kode_barang', $code)
                    ->select(['id', 'no_npbg', 'tgl_npbg', 'pelanggan', 'kuantitas', 'satuan', 'divisi', 'no_seri'])
                    ->get();
                $npbgIds = $npbgRows->pluck('id');
                $nopolByNpbg = [];
                if ($npbgIds->isNotEmpty()) {
                    foreach (DB::table('tyre_changes')->whereIn('out_npbg_id', $npbgIds)->whereNotNull('nopol_raw')->pluck('nopol_raw', 'out_npbg_id') as $npbgId => $nopol) {
                        $nopolByNpbg[$npbgId] = $nopol;
                    }
                    foreach (DB::table('maintenance_order_subs')->whereIn('npbg_id', $npbgIds)->whereNotNull('nopol_raw')->pluck('nopol_raw', 'npbg_id') as $npbgId => $nopol) {
                        $nopolByNpbg[$npbgId] ??= $nopol;
                    }
                }
                $keluar = $npbgRows->map(function ($n) use ($nopolByNpbg) {
                    $nopol = $nopolByNpbg[$n->id] ?? null;

                    return [
                        'type' => 'KELUAR', 'date' => $n->tgl_npbg, 'doc_no' => $n->no_npbg,
                        'counterparty_label' => $nopol ? 'Nopol' : 'Pelanggan', 'counterparty' => $nopol ?: $n->pelanggan,
                        'qty' => $n->kuantitas, 'satuan' => $n->satuan, 'no_seri' => $n->no_seri, 'note' => $n->divisi,
                    ];
                });

                $adjust = StockMovement::where('item_id', $item->id)
                    ->where('movement_type', 'STOCK_ADJUSTMENT')
                    ->with('warehouse:id,code')
                    ->get()
                    ->map(fn ($m) => [
                        'type' => 'ADJUST', 'date' => $m->created_at, 'doc_no' => null,
                        'counterparty_label' => 'Gudang', 'counterparty' => $m->warehouse?->code,
                        'qty' => $m->direction < 0 ? -$m->qty : $m->qty, 'satuan' => null, 'no_seri' => null, 'note' => $m->note,
                    ]);

                $all = $masuk->concat($keluar)->concat($adjust)
                    ->filter(fn ($r) => $r['date'] !== null)
                    ->sortByDesc('date')
                    ->values();

                return [
                    "Histori Barang {$item->code} - {$item->description}",
                    ['Tanggal', 'Jenis', 'No. Dokumen', 'Vendor / Pelanggan / Nopol', 'No. Seri', 'Qty', 'Satuan', 'Catatan'],
                    $all->map(fn ($r) => [
                        $r['date']?->toDateString(), $r['type'], $r['doc_no'],
                        $r['counterparty'] ? "{$r['counterparty_label']}: {$r['counterparty']}" : null,
                        $r['no_seri'],
                        $r['qty'], $r['satuan'], $r['note'],
                    ]),
                ];
            })(),
            'inventory' => [
                'Laporan Inventory',
                ['Kode', 'Deskripsi', 'Gudang', 'Aktual', 'Reserved', 'Tersedia', 'Stok Diketahui'],
                Inventory::query()->with('item:id,code,description', 'warehouse:id,code')
                    ->join('items', 'items.id', '=', 'inventory.item_id')->orderBy('items.code')
                    ->select('inventory.*')->lazy()->map(fn ($i) => [
                        $i->item?->code, $i->item?->description, $i->warehouse?->code,
                        $i->actual_qty, $i->reserved_qty, $i->available_qty, $i->stock_known ? 'Ya' : 'Tidak',
                    ]),
            ],
            // Selalu dihitung langsung (real-time, bukan snapshot) dan mengikuti filter
            // yang sedang aktif di halaman Analisis Inventory — sumber tunggal yang sama
            // dipakai InventoryController::analysis(), lihat InventoryAnalyzer::filtered().
            // ?columns=kode,deskripsi,... (lihat INVENTORY_ANALYSIS_COLUMNS) — kosong = semua
            // kolom; ini bagaimana fitur "hide kolom" di halaman ikut ke hasil cetak/export.
            'inventory_analysis' => (function () use ($request, $analyzer) {
                $columns = self::inventoryAnalysisColumns();
                $requested = array_filter(array_map('trim', explode(',', (string) $request->string('columns'))));
                $keys = $requested !== []
                    ? array_values(array_intersect(array_keys($columns), $requested))
                    : array_keys($columns);
                if ($keys === []) {
                    $keys = array_keys($columns);
                }

                return [
                    'Analisis Inventory',
                    array_map(fn ($k) => $columns[$k][0], $keys),
                    $analyzer->filtered($request)['rows']->map(
                        fn ($r) => array_map(fn ($k) => $columns[$k][1]($r), $keys)
                    ),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman NPBG — sumber tunggal
            // yang sama dipakai NpbgController::index().
            'npbg' => (function () use ($request) {
                $query = Npbg::query();
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('no_npbg', 'like', "%{$s}%")
                        ->orWhere('kode_barang', 'like', "%{$s}%")
                        ->orWhereLoose('deskripsi_barang', $s)
                        ->orWhere('peminta', 'like', "%{$s}%")
                        ->orWhere('lokasi_rak', 'like', "%{$s}%")
                        ->orWhere('no_seri', 'like', "%{$s}%")
                        ->orWhere('divisi', 'like', "%{$s}%")
                        ->orWhere('pelanggan', 'like', "%{$s}%")
                        ->orWhereLoose('keterangan', $s));
                }
                $request->whenFilled('tipe_npbg', fn ($v) => $query->where('tipe_npbg', $v));
                $request->whenFilled('klasifikasi', fn ($v) => $query->where('klasifikasi', $v));
                $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
                $request->whenFilled('jenis_pengeluaran', fn ($v) => $query->where('klasifikasi', $v));
                $request->whenFilled('pelanggan', fn ($v) => $query->where('pelanggan', $v));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_npbg', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_npbg', '<=', $v));

                return [
                    'Laporan NPBG',
                    ['No NPBG', 'Tgl NPBG', 'Kode Barang', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'No. Seri', 'Peminta', 'Divisi', 'Klasifikasi', 'Pelanggan', 'Lokasi Gudang', 'Lokasi Rak', 'Keterangan'],
                    $query->orderBy('tgl_npbg')->lazy()->map(fn ($n) => [
                        $n->no_npbg, $n->tgl_npbg?->toDateString(), $n->kode_barang, $n->deskripsi_barang, $n->kuantitas,
                        $n->satuan, $n->no_seri, $n->peminta, $n->divisi, $n->klasifikasi, $n->pelanggan, $n->lokasi_gudang, $n->lokasi_rak, $n->keterangan,
                    ]),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman PPB — sumber tunggal yang
            // sama dipakai PpbController::index(). ?columns=key1,key2 (lihat
            // ppbColumns()) — kosong = semua kolom; begitulah hide-kolom di layar
            // ikut ke hasil cetak/export.
            'ppb' => (function () use ($request) {
                $query = Ppb::query()->with(
                    'poItems:id,accurate_ppb_id,purchase_order_id',
                    'poItems.purchaseOrder:id,number,date,vendor_id',
                    'poItems.purchaseOrder.vendor:id,name',
                    'poItems.riLines:id,accurate_po_item_id,no_ri,tgl_ri',
                );
                // free text, word by word: every word must be found somewhere on
                // this PPB line — no. PPB, barang, peminta, divisi, catatan, atau
                // no. PO/RI yang terhubung
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
                        ->orWhereHas('poItems.riLines', fn ($r) => $r->where('no_ri', 'like', "%{$w}%")));
                }
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
                $request->whenFilled('line_status', fn ($v) => strtoupper((string) $v) === \App\Support\PpbLineStatus::AMEND
                    ? $query->where('is_amend', true)
                    : $query->where('line_status', strtoupper((string) $v)));
                $request->whenFilled('amend', fn ($v) => $query->where('is_amend', filter_var($v, FILTER_VALIDATE_BOOLEAN)));
                $request->whenFilled('divisi', fn ($v) => $query->where('divisi', $v));
                $request->whenFilled('vendor_id', fn ($v) => $v === 'none'
                    ? $query->whereDoesntHave('poItems')
                    : $query->whereHas('poItems.purchaseOrder', fn ($p) => $p->where('vendor_id', $v)));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ppb', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ppb', '<=', $v));

                $columns = self::ppbColumns();
                $keys = self::requestedColumnKeys($request, $columns);
                $flat = collect($keys)->flatMap(fn ($k) => $columns[$k])->values();

                return [
                    'Laporan PPB',
                    $flat->map(fn ($c) => $c[0])->all(),
                    $query->orderByDesc('tgl_ppb')->lazy()->map(fn ($p) => $flat->map(fn ($c) => $c[1]($p))->all()),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman Purchase Order (status,
            // vendor, divisi, tanggal, sumber, pencarian) — sumber tunggal yang sama
            // dipakai PurchaseOrderController::index(). Satu baris per barang (seperti
            // PPB/RI). ?columns=key1,key2 (lihat purchaseOrderColumns()) — kosong =
            // semua kolom; begitulah hide-kolom di layar ikut ke hasil cetak/export.
            'purchase-orders' => (function () use ($request) {
                $query = PurchaseOrderItem::query()
                    ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                    ->with(
                        'purchaseOrder:id,number,date,vendor_id,status,notes,accurate_po_id',
                        'purchaseOrder.vendor:id,name',
                        'item:id,code,description', 'unit:id,code',
                        'accuratePpb:id,no_ppb,tgl_ppb,divisi', 'riLines:id,accurate_po_item_id,no_ri,tgl_ri',
                    )
                    ->select('purchase_order_items.*');
                // the Status column of this list is the line's own status (PENDING / PARTIAL_RECEIVED / RECEIVED / CLOSED)
                $request->whenFilled('status', fn ($v) => $query->where('purchase_order_items.line_status', strtoupper((string) $v)));
                $request->whenFilled('vendor_id', fn ($v) => $query->where('purchase_orders.vendor_id', $v));
                $request->whenFilled('divisi', fn ($v) => $query->whereHas('accuratePpb', fn ($p) => $p->where('divisi', $v)));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('purchase_orders.date', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('purchase_orders.date', '<=', $v));
                $request->whenFilled('source', fn ($v) => $v === 'accurate' ? $query->whereNotNull('purchase_orders.accurate_po_id') : ($v === 'internal' ? $query->whereNull('purchase_orders.accurate_po_id') : null));
                foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    $query->where(fn ($q) => $q->where('purchase_orders.number', 'like', "%{$w}%")
                        ->orWhere('purchase_orders.notes', 'like', "%{$w}%")
                        ->orWhereHas('purchaseOrder.vendor', fn ($v) => $v->where('name', 'like', "%{$w}%")->orWhere('code', 'like', "%{$w}%"))
                        ->orWhereLoose('purchase_order_items.description_raw', $w)
                        ->orWhereHas('item', fn ($it) => $it->where('code', 'like', "%{$w}%")->orWhereLoose('description', $w))
                        ->orWhereHas('accuratePpb', fn ($p) => $p->where('no_ppb', 'like', "%{$w}%")->orWhere('divisi', 'like', "%{$w}%"))
                        ->orWhereHas('riLines', fn ($r) => $r->where('no_ri', 'like', "%{$w}%")));
                }

                $columns = self::purchaseOrderColumns();
                $keys = self::requestedColumnKeys($request, $columns);
                $flat = collect($keys)->flatMap(fn ($k) => $columns[$k])->values();

                return [
                    'Laporan Purchase Order',
                    $flat->map(fn ($c) => $c[0])->all(),
                    $query->orderByDesc('purchase_orders.created_at')->orderBy('purchase_order_items.accurate_seq')
                        ->lazy()->map(fn (PurchaseOrderItem $l) => $flat->map(fn ($c) => $c[1]($l))->all()),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman RI — sumber tunggal yang
            // sama dipakai RiController::index().
            'ri' => (function () use ($request) {
                $query = Ri::query()->with(
                    'poItem:id,purchase_order_id,accurate_ppb_id',
                    'poItem.purchaseOrder:id,number,date',
                    'poItem.accuratePpb:id,no_ppb,tgl_ppb',
                );
                // free text, word by word: every word must be found somewhere on
                // this RI line — no. RI, barang, vendor, pemeriksa, no. PPB/PO, dll
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
                $request->whenFilled('vendor', fn ($v) => $query->where('vendor', $v));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('tgl_ri', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('tgl_ri', '<=', $v));

                $columns = self::riColumns();
                $keys = self::requestedColumnKeys($request, $columns);
                $flat = collect($keys)->flatMap(fn ($k) => $columns[$k])->values();

                return [
                    'Laporan RI',
                    $flat->map(fn ($c) => $c[0])->all(),
                    $query->orderByDesc('tgl_ri')->lazy()->map(fn ($r) => $flat->map(fn ($c) => $c[1]($r))->all()),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman Stock Opname (tab "Per
            // dokumen") — sumber tunggal yang sama dipakai StockOpnameController::index().
            'stock-opnames' => (function () use ($request) {
                $query = StockOpname::query()->withCount('items')->with('warehouse:id,code');
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
                $request->whenFilled('warehouse_id', fn ($v) => $query->where('warehouse_id', $v));
                $request->whenFilled('source', fn ($v) => $v === 'accurate'
                    ? $query->whereNotNull('accurate_itemadj_id')
                    : ($v === 'internal' ? $query->whereNull('accurate_itemadj_id') : null));
                $request->whenFilled('search', fn ($v) => $query->where('number', 'like', '%'.trim((string) $v).'%'));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('scheduled_date', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('scheduled_date', '<=', $v));

                return [
                    'Laporan Stock Opname',
                    ['Nomor', 'Status', 'Gudang', 'Jumlah Baris', 'Tanggal'],
                    $query->latest()->lazy()->map(fn ($o) => [
                        $o->number, $o->status, $o->warehouse?->code, $o->items_count, $o->scheduled_date?->toDateString(),
                    ]),
                ];
            })(),
            // Mengikuti filter yang sedang aktif di halaman Stock Opname (tab "Per
            // barang") — sumber tunggal yang sama dipakai StockOpnameController::lines().
            // ?columns=key1,key2 — kosong = semua kolom yang tersedia untuk user ini
            // (Qty sebelum/Selisih hanya untuk opname.review, sama seperti di layar).
            'stock-opname-lines' => (function () use ($request) {
                $canReview = $request->user()->hasPermission('opname.review');
                $acc = $request->string('source')->value() === 'accurate';

                $query = StockOpnameItem::query()
                    ->join('stock_opnames as so', 'so.id', '=', 'stock_opname_items.stock_opname_id')
                    ->join('items as it', 'it.id', '=', 'stock_opname_items.item_id')
                    ->select('stock_opname_items.*')
                    ->with(['item:id,code,description,unit_id', 'item.unit:id,code', 'opname:id,number,status,scheduled_date,accurate_itemadj_id'])
                    ->orderByDesc('so.scheduled_date')->orderByDesc('so.id')->orderBy('stock_opname_items.id');

                $request->whenFilled('source', fn ($v) => $v === 'accurate'
                    ? $query->whereNotNull('so.accurate_itemadj_id')
                    : ($v === 'internal' ? $query->whereNull('so.accurate_itemadj_id') : null));
                $request->whenFilled('status', fn ($v) => $query->where('so.status', strtoupper((string) $v)));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('so.scheduled_date', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('so.scheduled_date', '<=', $v));
                $request->whenFilled('search', function ($v) use ($query) {
                    $like = '%'.trim((string) $v).'%';
                    $query->where(fn ($q) => $q->where('so.number', 'like', $like)
                        ->orWhere('it.code', 'like', $like)
                        ->orWhereLoose('it.description', (string) $v));
                });
                if ($canReview && $request->boolean('only_diff')) {
                    $query->whereRaw('abs(stock_opname_items.difference) > 0.001');
                }

                // NPBG/PPB usage counts, same period picker as the screen (usage_from/
                // usage_to) — one grouped query each over the distinct item codes in
                // this result set, not per-row (would be an N+1 over a lazy stream).
                $usageFrom = $request->input('usage_from') ?: null;
                $usageTo = $request->input('usage_to') ?: null;
                $codes = (clone $query)->reorder()->select('it.code')->distinct()->pluck('code')->filter()->values();
                $usage = ['npbg' => collect(), 'ppb' => collect()];
                if ($codes->isNotEmpty()) {
                    $usage['npbg'] = DB::table('npbg')->whereIn('kode_barang', $codes)
                        ->when($usageFrom, fn ($q) => $q->whereDate('tgl_npbg', '>=', $usageFrom))
                        ->when($usageTo, fn ($q) => $q->whereDate('tgl_npbg', '<=', $usageTo))
                        ->selectRaw('kode_barang, count(*) c')->groupBy('kode_barang')->pluck('c', 'kode_barang');
                    $usage['ppb'] = DB::table('ppb')->whereIn('kode_barang', $codes)
                        ->when($usageFrom, fn ($q) => $q->whereDate('tgl_ppb', '>=', $usageFrom))
                        ->when($usageTo, fn ($q) => $q->whereDate('tgl_ppb', '<=', $usageTo))
                        ->selectRaw('kode_barang, count(*) c')->groupBy('kode_barang')->pluck('c', 'kode_barang');
                }

                // Same columns as lineColumns in OpnameListPage.tsx — "Qty sebelum" /
                // "Selisih" only exist for a reviewer (blind count, same rule as the
                // screen); "Catatan" only for internal opname (Accurate has none).
                $columns = [
                    'date' => [['Tanggal', fn ($l) => $l->opname?->scheduled_date?->toDateString()]],
                    'doc' => [['No. Dokumen', fn ($l) => $l->opname?->number]],
                    'item' => [['Kode Barang', fn ($l) => $l->item?->code], ['Deskripsi Barang', fn ($l) => $l->item?->description]],
                    'unit' => [['Satuan', fn ($l) => $l->item?->unit?->code]],
                    'npbg' => [['NPBG', fn ($l) => $usage['npbg'][$l->item?->code] ?? 0]],
                    'ppb' => [['PPB', fn ($l) => $usage['ppb'][$l->item?->code] ?? 0]],
                ];
                if ($canReview) {
                    $columns['before'] = [[$acc ? 'Qty Sebelum' : 'Stok Sistem', fn ($l) => $l->system_qty]];
                }
                $columns['after'] = [[$acc ? 'Qty Sesudah' : 'Jumlah Fisik', fn ($l) => $l->physical_qty]];
                if ($canReview) {
                    $columns['diff'] = [['Selisih', fn ($l) => $l->difference]];
                }
                if (! $acc) {
                    $columns['note'] = [['Catatan', fn ($l) => $l->note]];
                }
                $columns['status'] = [['Status', fn ($l) => $l->opname?->status]];

                $keys = self::requestedColumnKeys($request, $columns);
                $flat = collect($keys)->flatMap(fn ($k) => $columns[$k])->values();

                return [
                    'Laporan Stock Opname (Per Barang)',
                    $flat->map(fn ($c) => $c[0])->all(),
                    $query->lazy()->map(fn (StockOpnameItem $l) => $flat->map(fn ($c) => $c[1]($l))->all()),
                ];
            })(),
            'stock-movements' => [
                'Laporan Pergerakan Stok',
                ['Tanggal', 'Tipe', 'Arah', 'Kode Barang', 'Gudang', 'Qty', 'Aktual Sesudah', 'Catatan'],
                StockMovement::query()->with('item:id,code', 'warehouse:id,code')->latest('created_at')->lazy()
                    ->map(fn ($m) => [
                        $m->created_at?->toDateTimeString(), $m->movement_type,
                        $m->direction > 0 ? 'MASUK' : ($m->direction < 0 ? 'KELUAR' : '-'),
                        $m->item?->code, $m->warehouse?->code, $m->qty, $m->actual_after, $m->note,
                    ]),
            ],

            // ---------------------------------------------------------- Tracking
            // Masing-masing mengikuti filter (status, pencarian, dll) yang sama
            // dipakai halaman & controller-nya masing-masing.
            'lend' => (function () use ($request) {
                $query = LendTransaction::query()->with('item:id,code');
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('borrower_name', 'like', "%{$s}%")->orWhereLoose('description_raw', $s)
                        ->orWhere('requester_name', 'like', "%{$s}%")->orWhere('out_doc_no', 'like', "%{$s}%")->orWhere('return_doc_no', 'like', "%{$s}%")
                        ->orWhere('return_refs', 'like', "%{$s}%")->orWhere('condition_out', 'like', "%{$s}%"));
                }

                return [
                    'Laporan Peminjaman (Lend)',
                    ['No', 'Status', 'Kode Barang', 'Deskripsi', 'Qty', 'Peminjam', 'Keperluan', 'Tgl Keluar', 'Jatuh Tempo', 'Tgl Kembali', 'Tanda Keluar', 'Tanda Kembali', 'Catatan'],
                    $query->latest('out_date')->lazy()->map(fn (LendTransaction $l) => [
                        $l->sheet_no, $l->status, $l->item?->code, $l->description_raw, $l->qty, $l->borrower_name, $l->purpose,
                        $l->out_date?->toDateString(), $l->due_date?->toDateString(), $l->return_date?->toDateString(),
                        $l->out_doc_no, $l->return_doc_no ?? $l->return_refs, $l->return_note,
                    ]),
                ];
            })(),
            'borrow' => (function () use ($request) {
                $query = BorrowTransaction::query()->with('item:id,code', 'lenderVendor:id,name');
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('lender_name', 'like', "%{$s}%")
                        ->orWhere('description_raw', 'like', "%{$s}%")->orWhere('in_doc_no', 'like', "%{$s}%")->orWhere('out_doc_no', 'like', "%{$s}%")
                        ->orWhere('condition_note', 'like', "%{$s}%"));
                }

                return [
                    'Laporan Pinjam Luar (Borrow)',
                    ['No', 'Status', 'Kode Barang', 'Deskripsi', 'Qty', 'Pemberi Pinjam', 'Tgl Pinjam', 'Tgl Kembali', 'Tanda Terima', 'Tanda Keluar', 'Catatan'],
                    $query->latest('borrowed_at')->lazy()->map(fn (BorrowTransaction $b) => [
                        $b->sheet_no, $b->status, $b->item?->code, $b->description_raw, $b->qty,
                        $b->lenderVendor?->name ?? $b->lender_name, $b->borrowed_at?->toDateString(), $b->returned_at?->toDateString(),
                        $b->receipt_ref, $b->out_doc_no ?? $b->return_refs, $b->condition_note,
                    ]),
                ];
            })(),
            'stpp' => (function () use ($request) {
                $query = StppTransaction::query()->with('item:id,code');
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper((string) $v)));
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('serial_no_raw', 'like', "%{$s}%")
                        ->orWhere('description_raw', 'like', "%{$s}%")->orWhere('holder_name_raw', 'like', "%{$s}%")
                        ->orWhere('placement_raw', 'like', "%{$s}%")->orWhere('out_npbg_no', 'like', "%{$s}%")
                        ->orWhere('return_ri_no', 'like', "%{$s}%")->orWhere('out_note', 'like', "%{$s}%"));
                }

                return [
                    'Laporan STPP',
                    ['No', 'Status', 'No Seri', 'Deskripsi', 'Peminta', 'Penempatan', 'No NPBG', 'No RI', 'Keterangan'],
                    $query->latest('out_date')->lazy()->map(fn (StppTransaction $s) => [
                        $s->sheet_no, $s->status, $s->serial_no_raw, $s->description_raw, $s->holder_name_raw,
                        $s->placement_raw, $s->out_npbg_no, $s->return_ri_no, $s->out_note,
                    ]),
                ];
            })(),
            'tyre' => (function () use ($request) {
                $query = TyreChange::query()->with('asset:id,code,name');
                $request->whenFilled('status', fn ($v) => $query->where('status', strtoupper(str_replace(' ', '_', (string) $v))));
                $request->whenFilled('asset_id', fn ($v) => $query->where('asset_id', $v));
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('nopol_raw', 'like', "%{$s}%")
                        ->orWhereLoose('new_tyre_desc', $s)->orWhere('new_serial_raw', 'like', "%{$s}%")
                        ->orWhere('old_serial_raw', 'like', "%{$s}%")->orWhere('out_npbg_no', 'like', "%{$s}%")
                        ->orWhere('in_ri_no', 'like', "%{$s}%")->orWhere('out_note', 'like', "%{$s}%"));
                }

                return [
                    'Laporan Ban Luar',
                    ['No', 'Status', 'Nopol', 'Deskripsi Ban Baru', 'No Seri Baru', 'No Seri Lama', 'No NPBG', 'No RI', 'Keterangan'],
                    $query->latest('change_date')->lazy()->map(fn (TyreChange $t) => [
                        $t->sheet_no, $t->status, $t->asset?->code ?? $t->nopol_raw, $t->new_tyre_desc, $t->new_serial_raw,
                        $t->old_serial_raw, $t->out_npbg_no, $t->in_ri_no, $t->out_note,
                    ]),
                ];
            })(),
            // Per Sub SPK (satu baris per unit yang diperbaiki) — sama dengan tampilan
            // di halaman Maintenance, lihat MaintenanceController::lines().
            'maintenance' => (function () use ($request) {
                $query = DB::table('maintenance_order_subs as s')
                    ->join('maintenance_orders as o', 'o.id', '=', 's.maintenance_order_id')
                    ->leftJoin('workshops as w', 'w.id', '=', 's.workshop_id')
                    ->select('s.*', 'o.number', 'o.report_date', 'o.nopol_raw as order_nopol', 'w.name as workshop_name');
                $request->whenFilled('status', fn ($v) => $query->where('s.status', strtoupper(str_replace('-', '_', (string) $v))));
                if ($q = $request->string('search')->trim()->value()) {
                    $query->where(fn ($w) => $w->where('o.number', 'like', "%{$q}%")->orWhere('s.nopol_raw', 'like', "%{$q}%")
                        ->orWhere('o.nopol_raw', 'like', "%{$q}%")->orWhere('s.problem_detail', 'like', "%{$q}%")
                        ->orWhere('s.workshop_raw', 'like', "%{$q}%")->orWhere('s.npbg_no', 'like', "%{$q}%")
                        ->orWhere('s.result_note', 'like', "%{$q}%"));
                }

                return [
                    'Laporan Maintenance Assets',
                    ['No SPK', 'Status', 'Nopol', 'Bengkel', 'Keterangan Awal', 'No NPBG', 'Tgl Selesai', 'Keterangan Akhir'],
                    $query->orderByDesc('o.report_date')->orderByDesc('s.id')->lazy()->map(fn ($s) => [
                        $s->number, $s->status, $s->nopol_raw ?? $s->order_nopol, $s->workshop_raw ?? $s->workshop_name,
                        $s->problem_detail, $s->npbg_no, $s->finish_date, $s->result_note,
                    ]),
                ];
            })(),
            // Per sub-unit, difilter juga oleh `kind` (ASSEMBLY/JASA) seperti tab di
            // halaman Manufaktur & Assembly — lihat ManufacturingController::lines().
            'manufacturing' => (function () use ($request) {
                $kind = strtoupper($request->string('kind', 'ASSEMBLY')->value()) === 'JASA' ? 'JASA' : 'ASSEMBLY';
                $query = DB::table('manufacturing_order_subs as s')
                    ->join('manufacturing_orders as o', 'o.id', '=', 's.manufacturing_order_id')
                    ->leftJoin('vendors as v', 'v.id', '=', 'o.vendor_id')
                    ->where('o.kind', $kind)
                    ->select('s.*', 'o.number', 'o.date', 'o.location', 'o.product_name as order_product', 'v.name as vendor_name');
                $request->whenFilled('status', fn ($v) => $query->where('s.status', strtoupper(str_replace('-', '_', (string) $v))));
                if ($q = $request->string('search')->trim()->value()) {
                    $query->where(fn ($w) => $w->where('o.number', 'like', "%{$q}%")
                        ->orWhere('s.product_name', 'like', "%{$q}%")->orWhere('o.product_name', 'like', "%{$q}%")
                        ->orWhere('s.serial_no_raw', 'like', "%{$q}%")->orWhere('s.process', 'like', "%{$q}%")
                        ->orWhere('s.npbg_no', 'like', "%{$q}%")->orWhere('s.ri_no', 'like', "%{$q}%")
                        ->orWhere('s.row_location', 'like', "%{$q}%")->orWhere('o.location', 'like', "%{$q}%")
                        ->orWhere('s.note_start', 'like', "%{$q}%")->orWhere('s.note_end', 'like', "%{$q}%"));
                }

                return [
                    'Laporan Manufaktur & Assembly',
                    ['No', 'Status', 'No MA/MJ', 'Produk', 'No Seri', 'Proses', 'No NPBG', 'No RI', 'Keterangan Awal', 'Keterangan Akhir'],
                    $query->orderByDesc('o.date')->orderByDesc('s.id')->lazy()->map(fn ($s) => [
                        $s->sub_no, $s->status, $s->number, $s->product_name ?? $s->order_product, $s->serial_no_raw,
                        $s->process, $s->npbg_no, $s->ri_no, $s->note_start, $s->note_end,
                    ]),
                ];
            })(),
            // Per baris barang (sama persis bentuknya dengan RI) — lihat
            // UsedReturnController::index().
            'used-returns' => (function () use ($request) {
                $query = UsedReturnItem::query()
                    ->join('used_returns', 'used_returns.id', '=', 'used_return_items.used_return_id')
                    ->select('used_return_items.*', 'used_returns.number as ur_number', 'used_returns.status as ur_status', 'used_returns.return_date as ur_return_date')
                    ->with(['item:id,code,description', 'unit:id,code']);
                if ($s = $request->string('search')->trim()->value()) {
                    $query->where(fn ($q) => $q->where('used_returns.number', 'like', "%{$s}%")
                        ->orWhereHas('item', fn ($q2) => $q2->where('code', 'like', "%{$s}%")->orWhereLoose('description', $s))
                        ->orWhereLoose('used_return_items.description_raw', $s));
                }
                $request->whenFilled('status', fn ($v) => $query->where('used_returns.status', strtoupper((string) $v)));
                $request->whenFilled('date_from', fn ($v) => $query->whereDate('used_returns.return_date', '>=', $v));
                $request->whenFilled('date_to', fn ($v) => $query->whereDate('used_returns.return_date', '<=', $v));

                return [
                    'Laporan Pengembalian Bekas',
                    ['No UR', 'Status', 'Tgl Kembali', 'Kode Barang', 'Deskripsi', 'Kuantitas', 'Satuan', 'Kondisi', 'Masuk Stok'],
                    $query->orderByDesc('used_returns.return_date')->orderByDesc('used_return_items.id')->lazy()
                        ->map(fn (UsedReturnItem $li) => [
                            $li->ur_number, $li->ur_status, $li->ur_return_date,
                            $li->item?->code, $li->item?->description ?? $li->description_raw,
                            $li->qty, $li->unit?->code, $li->condition, $li->into_stock ? 'Ya' : 'Tidak',
                        ]),
                ];
            })(),
        };
    }
}
