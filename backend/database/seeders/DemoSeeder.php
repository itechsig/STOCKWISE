<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Models\Workshop;
use App\Services\GoodsIssueService;
use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\StockLedgerService;
use App\Services\RequestService;
use App\Services\StockOpnameService;
use App\Services\Tracking\BorrowService;
use App\Services\Tracking\LendService;
use App\Services\Tracking\MaintenanceService;
use App\Services\Tracking\ManufacturingService;
use App\Services\Tracking\StppService;
use App\Services\Tracking\TyreChangeService;
use App\Services\Tracking\UsedReturnService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data contoh yang menjalankan SETIAP flow lewat service-nya masing-masing
 * (bukan insert mentah), sehingga sekaligus memverifikasi integrasi antar-modul.
 *
 * Jalankan: php artisan db:seed --class=DemoSeeder
 * Reset   : php artisan migrate:fresh --seed  lalu seeder ini lagi.
 */
class DemoSeeder extends Seeder
{
    private Site $site;

    private Warehouse $wh;

    /** @var array<string,User> */
    private array $u = [];

    /** @var array<string,Item> */
    private array $items = [];

    private array $log = [];

    public function run(): void
    {
        if (Item::count() < 100) {
            $this->command->warn('DemoSeeder dilewati — master barang belum diimport (jalankan stockwise:import).');

            return;
        }

        $this->cleanup();

        $this->site = Site::where('code', 'SIG-SDA')->firstOrFail();
        $this->wh = Warehouse::where('site_id', $this->site->id)->where('code', 'GUDANG 1')->firstOrFail();

        foreach (['fauzan', 'rosul', 'misse', 'admingudang', 'adminlapangan', 'purchasing', 'kariawan'] as $name) {
            $this->u[$name] = User::where('username', $name)->firstOrFail();
        }
        $this->u['anakgudang'] = User::whereHas('roles', fn ($q) => $q->where('slug', 'anak_gudang'))->first()
            ?? $this->u['admingudang'];

        $this->masters();
        $this->stockedItems();

        $this->flowFullStock();
        $this->flowInProgressLeftovers();
        $this->flowOpname();
        $this->flowTracking();

        app(InventoryAnalyzer::class)->run($this->u['admingudang']->id);

        $this->command->info('DemoSeeder selesai:');
        foreach ($this->log as $line) {
            $this->command->line('  '.$line);
        }
    }

    /**
     * Kosongkan seluruh tabel transaksi + ledger sehingga demo dibangun dari nol
     * dan penomoran dokumen ikut reset. Hanya untuk DB dev/staging.
     */
    private function cleanup(): void
    {
        $tables = [
            'material_request_items', 'stock_reservations', 'material_requests',
            'goods_issue_items', 'goods_issues',
            'purchase_order_items', 'purchase_orders',
            'stock_opname_items', 'stock_adjustments', 'stock_opnames',
            'lend_transactions', 'borrow_transactions', 'stpp_transactions', 'tyre_changes',
            'maintenance_order_subs', 'maintenance_orders', 'manufacturing_order_subs', 'manufacturing_orders',
            'used_return_items', 'used_returns', 'serial_units',
            'stock_movements', 'document_sequences',
        ];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        // Reservasi sudah ikut terhapus — nolkan reserved_qty agar available benar.
        Inventory::query()->where('reserved_qty', '!=', 0)->update(['reserved_qty' => 0]);
        $this->command->warn('DemoSeeder: tabel transaksi & stock_movements dikosongkan, dibangun ulang.');
    }

    // ---------------------------------------------------------------- masters

    private function masters(): void
    {
        foreach (['CV Amarta Teknik', 'PT Sinar Baja Electric', 'UD Karya Logam', 'CV Mitra Gas Sejahtera', 'PT Indo Bearing'] as $n) {
            Vendor::firstOrCreate(['name' => $n], ['is_active' => true, 'source' => 'demo']);
        }
        foreach (['PT Petrokimia Gresik', 'RS Siloam Surabaya', 'PT Wilmar Nabati', 'Universitas Airlangga'] as $n) {
            Customer::firstOrCreate(['name' => $n], ['is_active' => true]);
        }
        foreach (['Revamping Line Oksigen', 'Pemeliharaan Tahunan 2026', 'Instalasi VGL Petrokimia'] as $n) {
            Project::firstOrCreate(['name' => $n], ['status' => 'ACTIVE']);
        }
        $assets = [
            ['code' => 'W 8747 PD', 'name' => 'HINO DUTRO 130 MDL', 'asset_type' => 'VEHICLE', 'brand_model' => 'HINO'],
            ['code' => 'W 9021 UY', 'name' => 'MITSUBISHI FUSO FE 74', 'asset_type' => 'VEHICLE', 'brand_model' => 'FUSO'],
            ['code' => 'FORKLIFT 3 TON', 'name' => 'FORKLIFT TOYOTA 3 TON', 'asset_type' => 'FORKLIFT', 'brand_model' => 'TOYOTA'],
            ['code' => 'EKOR TRAILER SIG-01', 'name' => 'EKOR TRAILER 20 FT', 'asset_type' => 'TRAILER_TAIL', 'brand_model' => null],
        ];
        foreach ($assets as $a) {
            Asset::firstOrCreate(['code' => $a['code']], $a + ['site_id' => $this->site->id, 'is_active' => true]);
        }
        Workshop::firstOrCreate(['name' => 'Bengkel Jaya Motor'], ['is_internal' => false, 'is_active' => true]);
        Workshop::firstOrCreate(['name' => 'Workshop SIG Sidoarjo'], ['is_internal' => true, 'site_id' => $this->site->id, 'is_active' => true]);

        $this->log[] = 'Master: 5 vendor, 4 customer, 3 project, 4 asset, workshop.';
    }

    private function stockedItems(): void
    {
        $ledger = app(StockLedgerService::class);
        // kode kurasi + qty awal; kalau kode tak ada, ambil item aktif urutan ke-N
        $wanted = [
            'MAI.0215' => 120, 'AUT.0243' => 60, 'OFN.0110' => 200, 'MAI.0141' => 90,
            'MAI.0304' => 150, 'MAI.0467' => 80, 'MAI.0373' => 110, 'MAI.0368' => 140,
            'HHN.0037' => 45, 'MAI.0342' => 70, 'AUT.0001' => 30, 'PUI.0140' => 500,
        ];
        $n = 0;
        foreach ($wanted as $code => $target) {
            $item = Item::where('code', $code)->first()
                ?? Item::where('is_active', true)->orderBy('id')->skip(300 + $n)->first();
            $n++;
            if (! $item) {
                continue;
            }
            $item->update(['default_warehouse_id' => $this->wh->id]);
            Inventory::firstOrCreate(
                ['item_id' => $item->id, 'warehouse_id' => $this->wh->id],
                ['actual_qty' => 0, 'reserved_qty' => 0]
            );
            $ledger->record([
                'type' => 'STOCK_ADJUSTMENT',
                'item_id' => $item->id,
                'warehouse_id' => $this->wh->id,
                'absolute' => (float) $target,
                'created_by' => $this->u['admingudang']->id,
                'note' => 'Saldo awal data demo',
            ]);
            Inventory::where('item_id', $item->id)->where('warehouse_id', $this->wh->id)
                ->update(['stock_known' => true, 'last_counted_at' => now()]);
            $this->items[$item->code] = $item->fresh();
        }
        // safety stock efektif untuk sebagian item → memunculkan status/priority
        foreach (array_slice($this->items, 0, 4) as $item) {
            $item->safetyStocks()->firstOrCreate(
                ['source_category' => 'demo'],
                ['safety_stock' => 40, 'min_pr' => 60, 'lead_time_days' => 7, 'is_effective' => true, 'period_label' => 'demo']
            );
        }
        $this->log[] = 'Stok awal: '.count($this->items).' barang di '.$this->wh->code.' (via ledger STOCK_ADJUSTMENT).';
    }

    private function itemList(): array
    {
        return array_values($this->items);
    }

    // ---------------------------------------------------------------- flows

    /** A — request stok cukup → NPBG → pickup → COMPLETED. */
    private function flowFullStock(): void
    {
        $reqSvc = app(RequestService::class);
        $goodsIssueSvc = app(GoodsIssueService::class);
        [$a, $b] = $this->itemList();

        foreach ([[$this->u['fauzan'], $a, 10.0, 'Perawatan rutin panel listrik'], [$this->u['rosul'], $b, 6.0, 'Ganti selang turbo unit W 8747 PD']] as $spec) {
            [$user, $item, $qty, $purpose] = $spec;

            $req = $reqSvc->create($user, [
                'purpose' => $purpose,
                'work_location' => 'DEMO', 'request_ip' => '192.168.20.15',
                'requester_wa' => $user->phone,
                'items' => [['item_id' => $item->id, 'description_raw' => $item->description, 'qty_requested' => $qty, 'unit_id' => $item->unit_id]],
            ]);
            $reqSvc->submit($req);
            $reqSvc->review($req->fresh(), $this->u['admingudang']);
            $line = $req->items()->first();
            $reqSvc->physicalCheck($line, $this->u['admingudang'], 'VERIFIED_MATCH', $qty, null);
            $req = $reqSvc->reserve($req->fresh(), $this->u['admingudang']);
            $this->assertState($req->status, ['RESERVED', 'READY'], "Flow A reserve {$req->number}");

            $before = $this->actual($item);
            $goodsIssue = $goodsIssueSvc->createFromRequest($req->fresh(), $this->u['admingudang']);
            $goodsIssueSvc->ready($goodsIssueSvc->prepare($goodsIssue));
            $goodsIssueSvc->pickup($goodsIssue->fresh(), $this->u['adminlapangan'], $user->name, null);

            $after = $this->actual($item);
            if (abs(($before - $qty) - $after) > 1e-6) {
                throw new RuntimeException("Flow A: stok {$item->code} harusnya {$before}-{$qty}={$before} - {$qty}, dapat {$after}");
            }
            $req->refresh();
            $this->assertState($req->status, ['COMPLETED'], "Flow A selesai {$req->number}");
            $this->log[] = "Flow A OK: {$req->number} → {$goodsIssue->number} → PICKED_UP. {$item->code} {$before}→{$after}. npbg_no={$req->npbg_no}";
        }
    }

    /** Sisa dokumen di tengah flow supaya dashboard punya antrian. */
    private function flowInProgressLeftovers(): void
    {
        $reqSvc = app(RequestService::class);
        $goodsIssueSvc = app(GoodsIssueService::class);
        $items = $this->itemList();

        // 1 request SUBMITTED (menunggu review)
        $r1 = $reqSvc->create($this->u['rosul'], [
            'purpose' => 'Perlengkapan APD tim shift malam',
            'work_location' => 'DEMO', 'request_ip' => '192.168.20.15', 'requester_wa' => $this->u['rosul']->phone,
            'items' => [
                ['item_id' => $items[5]->id, 'description_raw' => $items[5]->description, 'qty_requested' => 12, 'unit_id' => $items[5]->unit_id],
                ['item_id' => $items[6]->id, 'description_raw' => $items[6]->description, 'qty_requested' => 12, 'unit_id' => $items[6]->unit_id],
            ],
        ]);
        $reqSvc->submit($r1);

        // 1 request UNDER_REVIEW (menunggu cek fisik + reserve)
        $r2 = $reqSvc->create($this->u['misse'], [
            'purpose' => 'Consumable perawatan regulator',
            'work_location' => 'DEMO', 'request_ip' => '114.10.44.207', 'requester_wa' => $this->u['misse']->phone,
            'items' => [['item_id' => $items[3]->id, 'description_raw' => $items[3]->description, 'qty_requested' => 8, 'unit_id' => $items[3]->unit_id]],
        ]);
        $reqSvc->submit($r2);
        $reqSvc->review($r2->fresh(), $this->u['admingudang']);

        // 1 NPBG READY_TO_PICKUP (menunggu Lapangan Gudang serahkan)
        $r3 = $reqSvc->create($this->u['fauzan'], [
            'purpose' => 'Kabel & isolasi perbaikan penerangan gudang',
            'work_location' => 'DEMO', 'request_ip' => '192.168.20.15', 'requester_wa' => $this->u['fauzan']->phone,
            'items' => [['item_id' => $items[2]->id, 'description_raw' => $items[2]->description, 'qty_requested' => 15, 'unit_id' => $items[2]->unit_id]],
        ]);
        $reqSvc->submit($r3);
        $reqSvc->review($r3->fresh(), $this->u['admingudang']);
        $reqSvc->physicalCheck($r3->items()->first(), $this->u['admingudang'], 'VERIFIED_MATCH', $this->actual($items[2]), null);
        $reqSvc->reserve($r3->fresh(), $this->u['admingudang']);
        $goodsIssue = $goodsIssueSvc->createFromRequest($r3->fresh(), $this->u['admingudang']);
        $goodsIssueSvc->ready($goodsIssueSvc->prepare($goodsIssue));

        $this->log[] = "Leftovers OK: {$r1->number} SUBMITTED, {$r2->number} UNDER_REVIEW, {$goodsIssue->number} READY_TO_PICKUP.";
    }

    /** D — Stock Opname: hitung → submit → review (approve selisih). */
    private function flowOpname(): void
    {
        $svc = app(StockOpnameService::class);
        $items = $this->itemList();
        $scoped = [$items[1]->id, $items[4]->id, $items[7]->id];

        $opname = $svc->schedule($this->u['admingudang'], $this->wh->id, now()->toDateString(), 'PARTIAL', $scoped);
        $svc->start($opname->fresh(), $this->u['anakgudang']);

        $lines = $opname->fresh()->items()->get()->keyBy('item_id');
        $exact = $lines[$items[1]->id];
        $short = $lines[$items[4]->id];
        $over = $lines[$items[7]->id];

        $svc->count($exact, (float) $exact->system_qty, null);
        $svc->count($short, (float) $short->system_qty - 4, 'Selisih 4 — kemungkinan salah catat pengeluaran');
        $svc->count($over, (float) $over->system_qty + 3, 'Temuan 3 unit belum tercatat penerimaan');

        $svc->submit($opname->fresh());

        $before = [$short->item_id => $this->actual($items[4]), $over->item_id => $this->actual($items[7])];
        $svc->review($opname->fresh(), $this->u['admingudang'], [
            ['id' => $exact->id, 'decision' => 'APPROVED'],
            ['id' => $short->id, 'decision' => 'APPROVED'],
            ['id' => $over->id, 'decision' => 'APPROVED'],
        ], 'Semua temuan diverifikasi bersama Anak Gudang.');

        $afterShort = $this->actual($items[4]);
        $afterOver = $this->actual($items[7]);
        if (abs(($before[$short->item_id] - 4) - $afterShort) > 1e-6 || abs(($before[$over->item_id] + 3) - $afterOver) > 1e-6) {
            throw new RuntimeException('Flow D: penyesuaian opname tidak sesuai.');
        }
        $this->assertState($opname->fresh()->status, ['COMPLETED'], "Flow D {$opname->number}");
        $this->log[] = "Flow D OK: {$opname->number} COMPLETED. {$items[4]->code} -4, {$items[7]->code} +3 via STOCK_ADJUSTMENT.";

        // 1 opname lagi dibiarkan IN_PROGRESS
        $op2 = $svc->schedule($this->u['admingudang'], $this->wh->id, now()->addDay()->toDateString(), 'PARTIAL', [$items[0]->id, $items[2]->id]);
        $svc->start($op2->fresh(), $this->u['anakgudang']);
        $this->log[] = "Leftover: {$op2->number} IN_PROGRESS (menunggu hitung fisik).";
    }

    /** E–K — modul tracking. */
    private function flowTracking(): void
    {
        $lend = app(LendService::class);
        $borrow = app(BorrowService::class);
        $stpp = app(StppService::class);
        $tyre = app(TyreChangeService::class);
        $mtc = app(MaintenanceService::class);
        $mfg = app(ManufacturingService::class);
        $used = app(UsedReturnService::class);

        $items = $this->itemList();
        $cust = Customer::where('name', 'PT Petrokimia Gresik')->first();
        $proj = Project::where('name', 'Instalasi VGL Petrokimia')->first();
        $vendorJasa = Vendor::where('name', 'UD Karya Logam')->first();
        $asset1 = Asset::where('code', 'W 8747 PD')->first();
        $asset2 = Asset::where('code', 'FORKLIFT 3 TON')->first();

        // Lend: 1 ON_LOAN, 1 partial return
        $l1 = $lend->create($this->u['admingudang'], [
            'item_id' => $items[0]->id, 'description_raw' => $items[0]->description, 'qty' => 8,
            'purpose' => 'PROJECT', 'borrower_name' => 'Tim Proyek Petrokimia', 'customer_id' => $cust->id,
            'project_id' => $proj->id, 'est_days' => 14, 'condition_out' => 'Baik, lengkap',
        ]);
        $l2 = $lend->create($this->u['admingudang'], [
            'item_id' => $items[3]->id, 'description_raw' => $items[3]->description, 'qty' => 10,
            'purpose' => 'RELASI', 'borrower_name' => 'CV Amarta Teknik', 'est_days' => 7,
        ]);
        $lend->recordReturn($l2->fresh(), ['qty' => 6, 'condition_in' => 'Sebagian, 4 menyusul']);

        // Borrow: 1 aktif, 1 selesai
        $b1 = $borrow->create($this->u['admingudang'], [
            'description_raw' => 'Trafo las 500A', 'qty' => 1, 'lender_name' => 'Bengkel Jaya Motor', 'receipt_ref' => 'TT/BJM/014',
        ]);
        $b2 = $borrow->create($this->u['admingudang'], [
            'description_raw' => 'Chain block 2 ton', 'qty' => 2, 'lender_vendor_id' => Vendor::where('name', 'PT Indo Bearing')->first()->id,
        ]);
        $borrow->recordReturn($b2->fresh(), ['qty' => 2, 'condition_note' => 'Kembali lengkap']);

        // STPP: issue 2, withdraw 1, reissue
        $s1 = $stpp->issue($this->u['admingudang'], [
            'serial_no' => 'SN-2041', 'item_id' => $items[6]->id, 'description_raw' => 'Kacamata safety inventaris',
            'holder_name_raw' => 'Rosul', 'placement_raw' => 'Gudang Tabung', 'out_note' => 'Serah terima APD',
        ]);
        $s2 = $stpp->issue($this->u['admingudang'], [
            'serial_no' => 'SN-2042', 'description_raw' => 'Torque wrench 1/2"', 'holder_name_raw' => 'Tim Maintenance',
        ]);
        $stpp->withdraw($s2->fresh(), ['return_note' => 'Kalibrasi ulang']);
        $stpp->reissue($s2->fresh(), $this->u['admingudang'], ['holder_name_raw' => 'Tim Maintenance', 'out_note' => 'Kembali dari kalibrasi']);

        // Ban Luar: 1 PENDING_RI, 1 CLEAR, 1 opening
        $t1 = $tyre->record($this->u['admingudang'], [
            'asset_id' => $asset1->id, 'position' => 'FRONT_L', 'new_tyre_desc' => 'GT Radial GDL225 750-16',
            'new_serial_raw' => '2320611829', 'old_tyre_desc' => 'Bridgestone R150', 'old_serial_raw' => '2110550021',
            'reason' => 'Aus tidak rata',
        ]);
        $t2 = $tyre->record($this->u['admingudang'], [
            'asset_id' => $asset1->id, 'position' => 'REAR_RO', 'new_tyre_desc' => 'GT Radial GDL225 750-16', 'new_serial_raw' => '2320611830',
        ]);
        $tyre->close($t2->fresh(), []);
        $tyre->record($this->u['admingudang'], ['asset_id' => $asset2->id, 'position' => 'FRONT_R', 'is_opening' => true, 'new_tyre_desc' => 'Solid tyre 6.00-9']);

        // Maintenance: 1 order ON_GOING (1 sub selesai, 1 jalan), 1 order COMPLETED
        $m1 = $mtc->createOrder($this->u['admingudang'], [
            'asset_id' => $asset1->id, 'site_id' => $this->site->id, 'problem_summary' => 'Rem kurang pakem & lampu belakang mati',
        ]);
        $sub1 = $mtc->addSub($m1->fresh(), ['problem_detail' => 'Ganti kampas rem belakang', 'workshop_raw' => 'Bengkel Jaya Motor']);
        $mtc->addSub($m1->fresh(), ['problem_detail' => 'Perbaikan wiring lampu', 'workshop_raw' => 'Workshop SIG Sidoarjo']);
        $mtc->completeSub($sub1->fresh(), ['result_note' => 'Kampas rem diganti, rem normal', 'finish_date' => now()->toDateString()]);

        $m2 = $mtc->createOrder($this->u['admingudang'], [
            'asset_id' => $asset2->id, 'site_id' => $this->site->id, 'problem_summary' => 'Ganti oli hidrolik forklift',
        ]);
        $sub2 = $mtc->addSub($m2->fresh(), ['problem_detail' => 'Ganti oli + filter hidrolik', 'workshop_raw' => 'Workshop SIG Sidoarjo']);
        $mtc->completeSub($sub2->fresh(), ['result_note' => 'Selesai, level oli normal']);

        // Manufaktur: 1 ASSEMBLY, 1 JASA
        $mo1 = $mfg->createOrder($this->u['admingudang'], [
            'kind' => 'ASSEMBLY', 'site_id' => $this->site->id, 'product_name' => 'Manifold distribusi O2 6-way',
        ]);
        $mfg->addSub($mo1->fresh(), ['process' => 'Fabrikasi bracket', 'serial_no_raw' => 'SIG-58']);
        $mo2 = $mfg->createOrder($this->u['admingudang'], [
            'kind' => 'JASA', 'site_id' => $this->site->id, 'vendor_id' => $vendorJasa->id, 'product_name' => 'Bubut ulang as roller',
        ]);
        $mfg->addSub($mo2->fresh(), ['process' => 'Bubut & balancing']);

        // Pengembalian Bekas: 1 PENDING, 1 CLEAR
        $ur1 = $used->create($this->u['admingudang'], [
            'npbg_ref_raw' => 'NPBG/NA/26/IX/031',
            'items' => [
                ['item_id' => $items[9]->id, 'description_raw' => $items[9]->description, 'qty' => 5, 'condition' => 'REUSABLE', 'into_stock' => true],
                ['description_raw' => 'Baut GI bekas', 'qty' => -3, 'condition' => 'SCRAP'],
            ],
        ]);
        $ur2 = $used->create($this->u['admingudang'], [
            'npbg_ref_raw' => 'NPBG/NA/26/IX/033',
            'items' => [['item_id' => $items[10]->id ?? $items[0]->id, 'description_raw' => 'Sisa kabel', 'qty' => 2, 'condition' => 'USED', 'into_stock' => true]],
        ]);
        $used->close($ur2->fresh(), []);

        $this->log[] = 'Tracking OK: Lend 2, Borrow 2, STPP 2 (+withdraw+reissue), Ban Luar 3, Maintenance 2, Manufaktur 2, Pengembalian Bekas 2.';
    }

    // ---------------------------------------------------------------- helpers

    private function actual(Item $item): float
    {
        return (float) Inventory::where('item_id', $item->id)->where('warehouse_id', $this->wh->id)->value('actual_qty');
    }

    private function assertState(string $got, array $allowed, string $ctx): void
    {
        if (! in_array($got, $allowed, true)) {
            throw new RuntimeException("{$ctx}: status '{$got}' tidak termasuk [".implode(', ', $allowed).'].');
        }
    }
}
