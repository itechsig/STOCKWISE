<?php

namespace App\Services\Export\Legacy;

use App\Models\BorrowTransaction;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\MaintenanceOrderSub;
use App\Models\ManufacturingOrderSub;
use App\Models\Npbg;
use App\Models\StppTransaction;
use App\Models\TyreChange;
use App\Models\UsedReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Replika 1:1 dari 9 file Excel lama PT Surya Inti Gas (docs/excel-data-mapping.md) —
 * sheet, kolom, dan urutan sama persis; SAFETY STOCK * memakai formula Excel asli
 * (bukan angka jadi) sehingga tetap bisa dihitung ulang bila dibuka di Excel.
 * Data diambil live dari database STOCKWISE — inilah "Excel" yang menggantikan file manual.
 */
class LegacyExcelExportService
{
    /** nama sheet legacy => Kategori Induk (docs/excel-data-mapping.md katalog enum) */
    private const CATEGORY_SHEETS = [
        'SAFETY STOCK ASSETS' => 'Assets',
        'SAFETY STOCK AUTOMOTIVE' => 'Automotive',
        'SAFETY STOCK BIG SPAREPARTS' => 'Big Spare Parts',
        'SAFETY STOCK ELECTRONICS & ELEC' => 'Electronics & Electricals',
        'SAFETY STOCK ETALASE' => 'Etalase',
        'SAFETY STOCK HOUSE HOLD & NEEDS' => 'Household Needs',
        'SAFETY STOCK MAINTENANCE & INDU' => 'Maintenance & Industry',
        'SAFETY STOCK MANUFAKTUR & ASSEM' => 'Manufacture & Assembly',
        'SAFETY STOCK OFFICE APPAREL & A' => 'Office Apparel & Accessories',
        'SAFETY STOCK OFFICE NEEDS' => 'Office Needs',
        'SAFETY STOCK POST USE ITEM' => 'Post-Use Items',
        'SAFETY STOCK SMALL SPAREPARTS' => 'Small Spare Parts',
    ];

    /** kode used_return_component_types => label kolom matriks legacy (urutan asli) */
    private const COMPONENT_LABELS = [
        'BONIT_BR' => 'Bonit BR', 'PEN_BR' => 'Pen BR', 'PEN_SS' => 'Pen SS',
        'VALVE_BR' => 'Valve BR', 'CYL_CAP' => 'Cyl Cap',
        'MUR_BR' => 'Mur BR', 'MUR_CS' => 'Mur CS', 'MUR_GI' => 'Mur GI', 'MUR_SS' => 'Mur SS',
        'PER_CS' => 'Per CS',
        'BAUT_BR' => 'Baut BR', 'BAUT_CS' => 'Baut CS', 'BAUT_GI' => 'Baut GI', 'BAUT_SS' => 'Baut SS',
    ];

    private const BULAN = ['Agt', 'Sept', 'Okt', 'Nov', 'Des', 'Jan', 'Feb', 'Mar', 'April', 'Mei', 'Juni', 'Juli'];

    // ================================================================== DATA.xlsx

    public function dataMaster(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $this->buildDatabaseUtama($book);
        foreach (self::CATEGORY_SHEETS as $sheetName => $categoryName) {
            $this->buildSafetyStockSheet($book, $sheetName, $categoryName);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function buildDatabaseUtama(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('DATABASE UTAMA');

        $headers = [
            'Kode Barang', 'Kategori Induk', 'Kategori Anak 1', 'Kategori Anak 2', 'Kategori Anak 3',
            'Deskripsi Barang', 'UoM', 'Perlu Blueprint?', 'Nama Alias', 'LETAK GUDANG', 'LETAK RAK',
            'BLUEPRINT IMG', 'BLUEPRINT DETAIL PDF', 'BLUEPRINT 3D VIEW', 'SISA STOK', 'LEAD TIME',
            '√LT', 'SAFETY STOCK', 'MIN PR',
        ];
        $this->writeHeaderRow($sheet, 1, $headers);
        $sheet->freezePane('A2');

        $row = 2;
        Item::query()->where('is_active', true)
            ->with(['category', 'unit:id,code', 'defaultWarehouse:id,code', 'defaultLocation:id,code', 'effectiveSafetyStock', 'inventory'])
            ->orderBy('code')
            ->chunk(500, function (Collection $items) use ($sheet, &$row) {
                foreach ($items as $item) {
                    $cat = array_pad(explode(' > ', (string) $item->category?->path), 4, '');
                    $actual = $item->inventory->sum('actual_qty');
                    $stockKnown = $item->inventory->contains(fn ($i) => $i->stock_known);
                    $ss = $item->effectiveSafetyStock;

                    $sheet->fromArray([
                        $item->code, $cat[0], $cat[1], $cat[2], $cat[3],
                        $item->description, $item->unit?->code, $item->needs_blueprint ? 'Ya' : 'Tidak', 'Tidak',
                        $item->defaultWarehouse?->code, $item->defaultLocation?->code,
                        '', '', $item->blueprint_3d_ref,
                        $stockKnown ? 'STOK '.rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.').' '.$item->unit?->code : '',
                        $item->lead_time_days,
                        $ss?->sqrt_lt, $ss?->safety_stock, $ss?->min_pr,
                    ], null, "A{$row}");
                    $row++;
                }
            });

        // ribuan baris — lebar tetap, bukan autosize (mahal & berat memori di skala ini)
        $this->fixedWidths($sheet, [14, 16, 22, 22, 22, 42, 8, 12, 10, 14, 10, 12, 16, 16, 16, 10, 8, 12, 10]);
    }

    private function buildSafetyStockSheet(Spreadsheet $book, string $sheetName, string $categoryName): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($sheetName);

        // baris judul 2-lapis persis file asli
        $sheet->setCellValue('A3', 'NO');
        $sheet->setCellValue('B3', 'ITEM DESCRIPTION');
        $sheet->mergeCells('C3:N3');
        $sheet->setCellValue('C3', 'NPBG (12 bulan terakhir)');
        $sheet->mergeCells('O3:R3');
        $sheet->setCellValue('O3', 'RATA RATA PENGELUARAN');
        $sheet->setCellValue('S3', 'LT');
        $sheet->setCellValue('T3', '√LT');
        $sheet->setCellValue('U3', 'SS');
        $sheet->setCellValue('V3', 'MIN PR');
        foreach (self::BULAN as $i => $bulan) {
            $sheet->setCellValue([3 + $i, 4], $bulan);
        }
        $sheet->setCellValue('O4', '1 BLN');
        $sheet->setCellValue('P4', '3 BLN');
        $sheet->setCellValue('Q4', '6 BLN');
        $sheet->setCellValue('R4', '12 BLN');
        $this->styleHeaderRow($sheet, 3, 22);
        $this->styleHeaderRow($sheet, 4, 22);
        $sheet->freezePane('C5');

        $items = Item::query()->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('path', $categoryName)->orWhere('path', 'like', $categoryName.' > %'))
            ->with('unit:id,code')
            ->orderBy('description')
            ->get();

        // pemakaian bulanan 12 bulan terakhir dari movement STOCK_OUT (pengganti NPBG manual)
        $since = now()->subMonths(11)->startOfMonth();
        $monthly = DB::table('stock_movements')
            ->select('item_id', 'qty', 'created_at')
            ->where('movement_type', 'STOCK_OUT')
            ->where('created_at', '>=', $since)
            ->whereIn('item_id', $items->pluck('id'))
            ->get()
            ->groupBy('item_id')
            ->map(function ($rows) use ($since) {
                $byMonth = array_fill(0, 12, 0.0);
                foreach ($rows as $r) {
                    $idx = (int) $since->diffInMonths(Carbon::parse($r->created_at)->startOfMonth());
                    if ($idx >= 0 && $idx < 12) {
                        $byMonth[$idx] += (float) $r->qty;
                    }
                }

                return $byMonth;
            });

        $row = 5;
        $no = 1;
        foreach ($items as $item) {
            $months = $monthly->get($item->id, array_fill(0, 12, 0.0));
            $sheet->setCellValue("A{$row}", $no++);
            $sheet->setCellValue("B{$row}", $item->description);
            foreach ($months as $i => $qty) {
                $sheet->setCellValue([3 + $i, $row], $qty);
            }
            // formula ASLI dari DATA.xlsx (dikonfirmasi lewat inspeksi file, bukan rekaan)
            $sheet->setCellValue("O{$row}", "=SUM(C{$row}:N{$row})*1/12");
            $sheet->setCellValue("P{$row}", "=SUM(D{$row}:N{$row})*3/12");
            $sheet->setCellValue("Q{$row}", "=SUM(E{$row}:N{$row})*6/12");
            $sheet->setCellValue("R{$row}", "=SUM(F{$row}:N{$row})*12/12");
            $sheet->setCellValue("S{$row}", $item->lead_time_days ?: 0);
            $sheet->setCellValue("T{$row}", "=SQRT(S{$row}/30)");
            $sheet->setCellValue("U{$row}", "=ROUNDUP((2.33*O{$row})*T{$row},0)");
            $sheet->setCellValue("V{$row}", "=ROUNDUP((O{$row}*S{$row}/30)+1,0)");
            $row++;
        }

        $this->fixedWidths($sheet, array_merge([5, 42], array_fill(0, 12, 6), [8, 8, 8, 8, 6, 8, 6, 8]));
    }

    // ================================================================== 2. NPBG.xlsx

    public function npbg(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('NPBG');
        $this->writeHeaderRow($sheet, 1, [
            'Tgl NPBG', 'No NPBG', 'Tipe NPBG', 'Klasifikasi', 'Pelanggan', 'Nama Proyek', 'No Seri / Nopol',
            'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Dikeluarkan Oleh', 'Divisi', 'Keterangan',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        Npbg::query()->orderBy('tgl_npbg')
            ->chunk(200, function ($npbgs) use ($sheet, &$row) {
                foreach ($npbgs as $n) {
                    $sheet->fromArray([
                        $n->tgl_npbg?->toDateString(), $n->no_npbg, $n->tipe_npbg, $n->klasifikasi,
                        $n->pelanggan, $n->nama_proyek, $n->no_seri,
                        $n->deskripsi_barang, $n->kuantitas, $n->satuan,
                        $n->peminta, $n->dikeluarkan_oleh, $n->divisi, $n->keterangan,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 14);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 3. Tracking Borrow & Lend.xlsx

    public function borrowLend(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $photos = app(\App\Services\Tracking\TrackingPhotoService::class);

        // sheet "Lend" — columns of the reference Excel
        $sheet = $book->createSheet();
        $sheet->setTitle('Lend');
        $this->writeHeaderRow($sheet, 1, [
            'No', 'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Keperluan', 'Est. Pinjam (hari)',
            'Tanda Keluar', 'Keterangan Keluar', 'Status', 'Tanda Kembali', 'Tgl Kembali', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        LendTransaction::query()->with(['item', 'unit', 'outNpbg'])->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('out_date')->orderBy('id')
            ->chunk(200, function ($rows) use ($sheet, &$row, $photos) {
                $ph = $photos->forRows('lend', $rows->pluck('id'));
                foreach ($rows as $l) {
                    $p = $ph[$l->id] ?? [];
                    $sheet->fromArray([
                        $l->sheet_no ?? $row - 1, $l->out_date?->toDateString(), $l->description_raw ?? $l->item?->description, $l->qty, $l->unit_raw ?? $l->unit?->code,
                        $l->requester_name, $l->purpose, $l->est_days,
                        $l->out_doc_no ?? $l->outNpbg?->no_npbg ?? (isset($p['out']) ? '(gambar)' : null), $l->condition_out,
                        match ($l->status) { 'RETURNED' => 'KEMBALI', 'OVERDUE' => 'DEADLINE', 'INCOMPLETE' => '', default => 'SEDANG DIPINJAM' },
                        $l->return_doc_no ?? $l->return_refs ?? (isset($p['in']) ? '(gambar)' : null), $l->return_date?->toDateString() ?? $l->condition_in, $l->return_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 14);

        // sheet "Borrow"
        $sheet2 = $book->createSheet();
        $sheet2->setTitle('Borrow');
        $this->writeHeaderRow($sheet2, 1, [
            'No', 'Tgl Pinjam', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Vendor', 'Keterangan',
            'Tanda Terima', 'Status', 'Tanda Keluar', 'Keterangan Barang Kembali',
        ]);
        $sheet2->freezePane('A2');
        $row = 2;
        BorrowTransaction::query()->with(['item', 'unit', 'lenderVendor', 'returnNpbg'])->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('borrowed_at')->orderBy('id')
            ->chunk(200, function ($rows) use ($sheet2, &$row, $photos) {
                $ph = $photos->forRows('borrow', $rows->pluck('id'));
                foreach ($rows as $b) {
                    $p = $ph[$b->id] ?? [];
                    $sheet2->fromArray([
                        $b->sheet_no ?? $row - 1, $b->borrowed_at?->toDateString(), $b->description_raw ?? $b->item?->description, $b->qty, $b->unit_raw ?? $b->unit?->code,
                        $b->lenderVendor?->name ?? $b->lender_name, $b->condition_note,
                        $b->in_doc_no ?? $b->receipt_ref ?? (isset($p['in']) ? '(gambar)' : null),
                        match ($b->status) { 'RETURNED' => 'LUNAS', 'INCOMPLETE' => '', default => 'SEDANG DIPINJAM' },
                        $b->out_doc_no ?? $b->return_refs ?? $b->returnNpbg?->no_npbg ?? (isset($p['out']) ? '(gambar)' : null), $b->return_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet2, 11);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 4. Tracking STPP.xlsx

    public function stpp(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('STPP');
        // columns of the reference Excel sheet "STPP"
        $this->writeHeaderRow($sheet, 1, [
            'No', 'No Seri', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Penempatan', 'Tgl NPBG', 'No. NPBG', 'Item No',
            'Nama File(.#ext)', 'Bukti Keluar', 'Keterangan Keluar', 'Status', 'Tgl RI', 'Tanda Kembali', 'Item No2', 'Nama File(.#ext2)',
            'Bukti Terima', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        $photos = app(\App\Services\Tracking\TrackingPhotoService::class);
        StppTransaction::query()->with(['item', 'unit', 'outNpbg'])->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('id')
            ->chunk(200, function ($rows) use ($sheet, &$row, $photos) {
                $ph = $photos->forRows('stpp', $rows->pluck('id'));
                foreach ($rows as $s) {
                    $p = $ph[$s->id] ?? [];
                    $sheet->fromArray([
                        $s->sheet_no ?? $row - 1, $s->serial_no_raw, $s->description_raw ?? $s->item?->description, $s->qty, $s->unit_raw ?? $s->unit?->code,
                        $s->holder_name_raw, $s->placement_raw, $s->out_date?->toDateString(), $s->out_npbg_no ?? $s->outNpbg?->no_npbg, $s->out_item_no,
                        $s->out_file_name, isset($p['out']) ? 'Open File' : null, $s->out_note,
                        $s->status === 'INCOMPLETE' ? '' : $s->status, $s->return_date?->toDateString(), $s->return_ri_no, $s->return_item_no,
                        $s->return_file_name, isset($p['in']) ? 'Open File' : null, $s->return_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 20);

        $m = $book->createSheet();
        $m->setTitle('Maintenance');
        $this->writeHeaderRow($m, 1, ['No', 'No Seri', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Peminta', 'Tgl NPBG', 'No NPBG', 'Keterangan']);
        $r = 2;
        foreach (\Illuminate\Support\Facades\DB::table('stpp_maintenance_logs')->orderBy('sheet_no')->orderBy('id')->get() as $log) {
            $unit = StppTransaction::where('serial_no_raw', $log->serial_no_raw)->orderBy('id')->first();
            $m->fromArray([$log->sheet_no ?? $r - 1, $log->serial_no_raw, $unit?->description_raw ?? 'Not Found', $unit?->qty, $unit?->unit_raw,
                $log->requester, $log->npbg_date, $log->npbg_no, $log->note], null, "A{$r}");
            $r++;
        }
        $this->autosize($m, 9);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 5. Tracking Ban Luar.xlsx

    public function banLuar(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('Ban Luar');
        // columns of the reference Excel sheet "Ban Luar"
        $this->writeHeaderRow($sheet, 1, [
            'No', 'Nopol (Kendaraan)', 'Tgl NPBG', 'No NPBG', 'Deskripsi Ban Baru', 'No Seri Baru', 'Nama File', 'Foto Ban (Out)', 'Ban', 'Pergantian',
            'Keterangan Keluar', 'Status', 'Tgl RI', 'No. RI', 'Deskripsi Ban Lama', 'No Seri Lama', 'Nama File (#.ext2)', 'Foto Ban (In)', 'Keterangan Kembali',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        $photos = app(\App\Services\Tracking\TrackingPhotoService::class);
        TyreChange::query()->with(['asset', 'outNpbg', 'inRi'])->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('change_date')->orderBy('id')
            ->chunk(200, function ($rows) use ($sheet, &$row, $photos) {
                $ph = $photos->forRows('tyre', $rows->pluck('id'));
                foreach ($rows as $t) {
                    $p = $ph[$t->id] ?? [];
                    $sheet->fromArray([
                        $t->sheet_no ?? $row - 1,
                        $t->nopol_raw ?? ($t->asset ? "{$t->asset->code} ({$t->asset->brand_model})" : null),
                        $t->change_date?->toDateString(), $t->out_npbg_no ?? $t->outNpbg?->no_npbg,
                        $t->new_tyre_desc, $t->new_serial_raw, $t->new_file_name, isset($p['out']) ? 'Open File' : null,
                        $t->tyre_no ?? $t->position, $t->replacement_no ?? $t->change_seq,
                        $t->out_note ?? $t->reason,
                        match ($t->status) { 'PENDING_RI' => 'PENDING RI', 'CLEAR' => 'CLEAR', default => '' },
                        $t->in_date?->toDateString(), $t->in_ri_no ?? $t->inRi?->no_ri,
                        $t->old_tyre_desc, $t->old_serial_raw, $t->old_file_name, isset($p['in']) ? 'Open File' : null, $t->in_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 19);

        $bpn = $book->createSheet();
        $bpn->setTitle('Ban Luar BPN');
        $this->writeHeaderRow($bpn, 1, ['NO', 'TANGGAL CUT OFF', 'NOPOL', 'DESKRIPSI BAN', 'NO SERI', 'Foto', 'KETERANGAN']);
        $r = 2;
        $ph = $photos->forRows('tyre_bpn', \Illuminate\Support\Facades\DB::table('tyre_bpn_inventory')->pluck('id'));
        foreach (\Illuminate\Support\Facades\DB::table('tyre_bpn_inventory')->orderBy('sheet_no')->orderBy('id')->get() as $b) {
            $bpn->fromArray([$b->sheet_no ?? $r - 1, $b->cutoff_date, $b->nopol_raw, $b->tyre_desc, $b->serial_raw,
                isset($ph[$b->id]['photo']) ? 'Open File' : null, $b->note], null, "A{$r}");
            $r++;
        }
        $this->autosize($bpn, 7);

        $tr = $book->createSheet();
        $tr->setTitle('Deliver & Receive Ban SIG-BPN');
        $this->writeHeaderRow($tr, 1, ['No', 'NOPOL', 'Tgl NPBG', 'NO NPBG', 'Deskripsi Barang', 'No seri Ban Baru', 'Foto Ban', 'Ket',
            'Tgl RI', 'NO RI', 'Deskripsi Barang', 'No seri Ban Bekas', 'Foto Ban', 'Ket']);
        $r = 2;
        $ph = $photos->forRows('tyre_bpn_transfer', \Illuminate\Support\Facades\DB::table('tyre_bpn_transfers')->pluck('id'));
        foreach (\Illuminate\Support\Facades\DB::table('tyre_bpn_transfers')->orderBy('sheet_no')->orderBy('id')->get() as $b) {
            $tr->fromArray([$b->sheet_no ?? $r - 1, $b->nopol_raw, $b->npbg_date, $b->npbg_no, $b->new_tyre_desc, $b->new_serial_raw,
                isset($ph[$b->id]['out']) ? 'Open File' : null, $b->out_note, $b->ri_date, $b->ri_no, $b->old_tyre_desc, $b->old_serial_raw,
                isset($ph[$b->id]['in']) ? 'Open File' : null, $b->in_note], null, "A{$r}");
            $r++;
        }
        $this->autosize($tr, 14);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 6. Tracking Maintenance Assets.xlsx

    public function maintenanceAssets(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $sheet = $book->createSheet();
        $sheet->setTitle('Maintenance Kendaraan');
        // columns of the reference Excel sheet
        $this->writeHeaderRow($sheet, 1, [
            'No', 'Tgl Laporan', 'No. SPK', 'Sub SPK', 'Nopol (Kendaraan)', 'Keterangan Awal', 'Nama File', 'Foto Sebelum', 'Bengkel',
            'Permintaan', 'Status Hasil Pengerjaan', 'No. NPBG', 'Nama File 2', 'Foto Sesudah', 'Tgl Selesai Pengerjaan', 'Keterangan Akhir',
        ]);
        $sheet->freezePane('A2');
        $row = 2;
        $photos = app(\App\Services\Tracking\TrackingPhotoService::class);
        MaintenanceOrderSub::query()->with(['order.asset', 'workshop'])
            ->orderBy('sheet_no')->orderBy('id')
            ->chunk(200, function ($subs) use ($sheet, &$row, $photos) {
                $ph = $photos->forRows('maintenance_sub', $subs->pluck('id'));
                foreach ($subs as $sub) {
                    $order = $sub->order;
                    $p = $ph[$sub->id] ?? [];
                    $sheet->fromArray([
                        $sub->sheet_no ?? $row - 1, ($sub->report_date ?? $order->report_date)?->toDateString(), $order->number, $sub->sub_no,
                        $sub->nopol_raw ?? $order->nopol_raw ?? ($order->asset ? "{$order->asset->code} ({$order->asset->brand_model})" : null),
                        $sub->problem_detail ?? $order->problem_summary,
                        $sub->before_file_name, isset($p['before']) || $sub->before_file_name ? 'Open File' : null,
                        $sub->workshop?->name ?? $sub->workshop_raw,
                        $sub->request_note ?? (isset($p['request']) ? '(gambar)' : null),
                        match ($sub->status) { 'COMPLETED' => 'COMPLETED', 'ON_GOING' => 'ON-GOING', 'REQUESTED' => 'REQUESTED', default => '' },
                        $sub->npbg_no,
                        $sub->after_file_name, isset($p['after']) || $sub->after_file_name ? 'Open File' : null,
                        $sub->finish_date?->toDateString(), $sub->result_note,
                    ], null, "A{$row}");
                    $row++;
                }
            });
        $this->autosize($sheet, 16);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== 7. Tracking Manufaktur & Assembly.xlsx

    public function manufakturAssembly(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        // column order of each sheet of the reference Excel
        $sheets = [
            'Manufaktur & Assembly' => ['ASSEMBLY', ['No', 'Tanggal', 'No. Manufaktur & Assembly', 'Sub MA', 'Lokasi', 'Hasil Produk', 'No. Seri', 'Item No', 'Proses']],
            'Manufaktur & Jasa Lain-Lain' => ['JASA', ['No', 'Tanggal', 'No. Manufaktur & Jasa', 'Sub MJ', 'Item No', 'Lokasi', 'Hasil Produk', 'No. Seri', 'Proses']],
        ];
        $tail = ['Keterangan Awal', 'Status Hasil Pengerjaan', 'No. NPBG', 'Nama File (#.ext)', 'Foto Hasil', 'Tgl Selesai Pengerjaan', 'No. RI', 'Keterangan Akhir'];
        foreach ($sheets as $title => [$kind, $head]) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $this->writeHeaderRow($sheet, 1, array_merge($head, $tail));
            $sheet->freezePane('A2');
            $this->fillManufacturingRows($sheet, $kind);
            $this->autosize($sheet, count($head) + count($tail));
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function fillManufacturingRows(Worksheet $sheet, string $kind): void
    {
        $row = 2;
        $no = 1;
        ManufacturingOrderSub::query()->with(['order.vendor'])
            ->whereHas('order', fn ($q) => $q->where('kind', $kind))
            ->join('manufacturing_orders', 'manufacturing_orders.id', '=', 'manufacturing_order_subs.manufacturing_order_id')
            ->orderBy('manufacturing_order_subs.sheet_no')->orderBy('manufacturing_order_subs.id')
            ->select('manufacturing_order_subs.*')
            ->chunk(200, function ($subs) use ($sheet, &$row, &$no, $kind) {
                foreach ($subs as $sub) {
                    $order = $sub->order;
                    $lokasi = $sub->row_location ?? $order->location ?? $order->vendor?->name;
                    $product = $sub->product_name ?? $order->product_name;
                    $tanggal = ($sub->row_date ?? $order->date)?->toDateString();
                    $rowNo = $sub->sheet_no ?? $no;
                    $no++;
                    $head = $kind === 'JASA'
                        ? [$rowNo, $tanggal, $order->number, $sub->sub_no, $sub->item_no, $lokasi, $product, $sub->serial_no_raw, $sub->process]
                        : [$rowNo, $tanggal, $order->number, $sub->sub_no, $lokasi, $product, $sub->serial_no_raw, $sub->item_no, $sub->process];
                    $status = match ($sub->status) {
                        'COMPLETED' => 'COMPLETED', 'ON_GOING' => 'ON-GOING', 'REQUESTED' => 'REQUESTED', default => '',
                    };
                    $sheet->fromArray(array_merge($head, [
                        $sub->note_start, $status, $sub->npbg_no,
                        $sub->file_name,
                        $sub->file_name || $sub->photo_path ? 'Open File' : null,
                        $sub->finish_date?->toDateString(), $sub->ri_no, $sub->note_end,
                    ]), null, "A{$row}");
                    $row++;
                }
            });
    }

    // ================================================================== 8. Tracking Pengembalian Bekas.xlsx

    public function pengembalianBekas(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $S = \App\Services\Tracking\UsedReturnSheets::class;
        $DB = \Illuminate\Support\Facades\DB::class;

        // sheet "Spare Part" — columns of the reference Excel
        $sheet = $book->createSheet();
        $sheet->setTitle('Spare Part');
        $this->writeHeaderRow($sheet, 1, array_merge(['No', 'Tgl NPBG', 'No NPBG', 'Status', 'Tgl RI', 'No RI'], array_values($S::COMPONENTS), ['Keterangan']));
        $sheet->freezePane('A2');
        $row = 2;
        foreach ($DB::table($S::MATRIX)->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('id')->get() as $m) {
            $comp = (array) json_decode((string) $m->components, true);
            $line = [$m->sheet_no ?? $row - 1, $m->npbg_date, $m->npbg_no, $m->status === 'INCOMPLETE' ? '' : $m->status, $m->ri_date, $m->ri_no];
            foreach (array_keys($S::COMPONENTS) as $code) {
                $line[] = $comp[$code] ?? null;
            }
            $line[] = $m->note;
            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }
        $this->autosize($sheet, 6 + count($S::COMPONENTS) + 1);

        // sheet "Spare Part Lain"
        $sheet2 = $book->createSheet();
        $sheet2->setTitle('Spare Part Lain');
        $this->writeHeaderRow($sheet2, 1, ['No', 'Tgl NPBG', 'No NPBG', 'Deskripsi Barang', 'Kuantitas', 'Satuan', 'Item No', 'Nama File (#.ext)', 'Foto Keluar',
            'Status', 'No RI', 'Item No2', 'Nama File (#.ext2)', 'Foto Terima', 'Keterangan']);
        $sheet2->freezePane('A2');
        $row = 2;
        $rows = $DB::table($S::OTHER)->orderByRaw('sheet_no IS NULL')->orderBy('sheet_no')->orderBy('id')->get();
        $ph = app(\App\Services\Tracking\TrackingPhotoService::class)->forRows($S::PHOTO_ENTITY, $rows->pluck('id'));
        foreach ($rows as $o) {
            $sheet2->fromArray([
                $o->sheet_no ?? $row - 1, $o->npbg_date, $o->npbg_no, $o->description, $o->qty, $o->unit, $o->item_no,
                $o->out_file_name, isset($ph[$o->id]['out']) ? 'Open File' : null, $o->status === 'INCOMPLETE' ? '' : $o->status,
                $o->ri_no, $o->return_item_no, $o->in_file_name, isset($ph[$o->id]['in']) ? 'Open File' : null, $o->note,
            ], null, "A{$row}");
            $row++;
        }
        $this->autosize($sheet2, 15);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    // ================================================================== helpers

    private function writeHeaderRow(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $row], $h);
        }
        $this->styleHeaderRow($sheet, $row, count($headers));
    }

    private function styleHeaderRow(Worksheet $sheet, int $row, int $cols): void
    {
        $style = $sheet->getStyle([1, $row, $cols, $row]);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7ECF2');
        $style->getAlignment()->setWrapText(true)->setVertical('center');
    }

    private function autosize(Worksheet $sheet, int $cols): void
    {
        for ($c = 1; $c <= $cols; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    /** @param  list<int>  $widths  lebar per kolom — dipakai untuk sheet ribuan baris (autosize terlalu berat). */
    private function fixedWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $i => $w) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
    }
}
