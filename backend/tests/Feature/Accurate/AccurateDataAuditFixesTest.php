<?php

namespace Tests\Feature\Accurate;

use App\Models\InventoryAnalysisRun;
use App\Models\InventorySnapshot;
use App\Models\Item;
use App\Models\Npbg;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Services\Inventory\SafetyStockService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fixes from the 2026-09-29 audit of how Accurate data is read (verified
 * against the live staging tables):
 * - Accurate moves stock by QUANTITY × UNITRATIO (ITEMHIST) → usage must too;
 * - a long "ship to" is split over SHIPTO1 + SHIPTO2;
 * - dashboard counts use statuses that really exist, BEP counts as AMAN.
 */
class AccurateDataAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function stagingTables(): void
    {
        Schema::dropIfExists('accurate_arinv');
        Schema::dropIfExists('accurate_arinvdet');
        Schema::create('accurate_arinv', function ($table) {
            $table->integer('ARINVOICEID')->nullable();
            $table->string('INVOICENO')->nullable();
            $table->date('INVOICEDATE')->nullable();
            $table->date('SHIPDATE')->nullable();
            $table->date('TAXDATE')->nullable();
            $table->string('PURCHASEORDERNO')->nullable();
            $table->string('SHIPTO1')->nullable();
            $table->string('SHIPTO2')->nullable();
            $table->string('DESCRIPTION')->nullable();
        });
        Schema::create('accurate_arinvdet', function ($table) {
            $table->integer('ARINVOICEID')->nullable();
            $table->integer('SEQ')->nullable();
            $table->string('ITEMNO')->nullable();
            $table->string('ITEMOVDESC')->nullable();
            $table->decimal('QUANTITY', 14, 2)->nullable();
            $table->string('ITEMUNIT')->nullable();
            $table->decimal('UNITRATIO', 14, 4)->nullable();
            $table->string('ITEMRESERVED1')->nullable();
        });
        Process::fake();
    }

    public function test_npbg_keeps_the_unit_ratio_and_the_whole_ship_to(): void
    {
        $this->stagingTables();
        DB::table('accurate_arinv')->insert([
            'ARINVOICEID' => 4312, 'INVOICENO' => 'NA/26/IX/212', 'INVOICEDATE' => '2026-09-28',
            'PURCHASEORDERNO' => 'MAINTENANCE', 'SHIPTO1' => 'W 8348 QG (MITSUBISHI', 'SHIPTO2' => 'RAGASA)',
        ]);
        DB::table('accurate_arinvdet')->insert([
            ['ARINVOICEID' => 4312, 'SEQ' => 0, 'ITEMNO' => 'AUT.0465', 'QUANTITY' => 1, 'ITEMUNIT' => 'SET', 'UNITRATIO' => 2],
            ['ARINVOICEID' => 4312, 'SEQ' => 1, 'ITEMNO' => 'AUT.0001', 'QUANTITY' => 3, 'ITEMUNIT' => 'PCS', 'UNITRATIO' => null],
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $set = Npbg::where('kode_barang', 'AUT.0465')->firstOrFail();
        $this->assertEquals(1, $set->kuantitas, 'quantity stays as typed in Accurate');
        $this->assertEquals(2, $set->unit_ratio);
        $this->assertSame('W 8348 QG (MITSUBISHI RAGASA)', $set->pelanggan);
        $this->assertEquals(1, Npbg::where('kode_barang', 'AUT.0001')->value('unit_ratio'), 'missing ratio = 1');
    }

    public function test_usage_average_is_in_base_units(): void
    {
        Npbg::create([
            'accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/1', 'tgl_npbg' => now()->subDays(5)->toDateString(),
            'kode_barang' => 'AUT.0465', 'kuantitas' => 3, 'satuan' => 'SET', 'unit_ratio' => 2,   // 6 PCS
        ]);
        Npbg::create([
            'accurate_arinvoice_id' => 2, 'accurate_seq' => 0, 'no_npbg' => 'NA/2', 'tgl_npbg' => now()->subDays(6)->toDateString(),
            'kode_barang' => 'AUT.0465', 'kuantitas' => 3, 'satuan' => 'PCS', 'unit_ratio' => 1,   // 3 PCS
        ]);

        $usage = app(SafetyStockService::class)->usageAveragesFromNpbg();

        $this->assertEquals(3.0, $usage['AUT.0465']['avg_usage_3m'], '(6 + 3) / 3 months');
        $this->assertEquals(9.0, $usage['AUT.0465']['avg_usage_1m']);
    }

    public function test_dashboard_counts_bep_as_aman_and_pending_review_opnames_as_active(): void
    {
        $wh = Warehouse::factory()->create();
        InventoryAnalysisRun::forceCreate(['scope' => 'global', 'lead_time_threshold' => 7, 'median_deficit' => 1, 'item_count' => 3, 'tidak_aman_count' => 1, 'computed_at' => now()]);
        foreach (['AMAN', 'TIDAK_AMAN', 'BEP'] as $status) {
            InventorySnapshot::forceCreate(['item_id' => Item::factory()->create()->id, 'status' => $status] + $this->snapshotDefaults());
        }
        $base = ['warehouse_id' => $wh->id, 'site_id' => $wh->site_id, 'type' => 'PARTIAL', 'scheduled_date' => now()->toDateString()];
        StockOpname::forceCreate($base + ['number' => 'SO-1', 'status' => 'PENDING_REVIEW']);
        StockOpname::forceCreate($base + ['number' => 'SO-2', 'status' => 'COMPLETED']);
        StockOpname::forceCreate($base + ['number' => '3581', 'status' => 'PENDING_REVIEW', 'accurate_itemadj_id' => 2689]);

        $this->actingAsRole('super_admin');
        $cards = collect($this->getJson('/api/dashboard')->assertOk()->json('data.cards'))->keyBy('key');

        $this->assertSame(2, $cards['aman']['value'], 'AMAN + BEP');
        $this->assertSame(1, $cards['tidak_aman']['value']);
        $this->assertSame(1, $cards['opname_active']['value'], 'internal PENDING_REVIEW only');
    }

    public function test_po_closed_in_accurate_after_partial_receipt_is_closed_not_open(): void
    {
        Schema::dropIfExists('accurate_po');
        Schema::dropIfExists('accurate_podet');
        Schema::dropIfExists('accurate_persondata');
        Schema::create('accurate_po', function ($t) {
            foreach (['POID' => 'integer', 'PONO' => 'string', 'PODATE' => 'date', 'EXPECTED' => 'date', 'CLOSED' => 'integer',
                'POAMOUNT' => 'decimal', 'TAX1AMOUNT' => 'decimal', 'TAX2AMOUNT' => 'decimal', 'DESCRIPTION' => 'string', 'VENDORID' => 'integer'] as $c => $type) {
                $t->{$type}($c)->nullable();
            }
        });
        Schema::create('accurate_podet', function ($t) {
            foreach (['POID' => 'integer', 'SEQ' => 'integer', 'ITEMNO' => 'string', 'ITEMOVDESC' => 'string', 'QUANTITY' => 'decimal',
                'QTYRECV' => 'decimal', 'UNITPRICE' => 'decimal', 'ITEMUNIT' => 'string', 'CLOSED' => 'integer', 'REQID' => 'integer', 'REQSEQ' => 'integer'] as $c => $type) {
                $t->{$type}($c)->nullable();
            }
        });
        Schema::create('accurate_persondata', fn ($t) => [$t->integer('ID')->nullable(), $t->string('NAME')->nullable()]);
        Process::fake();

        $po = fn ($id, $closed) => DB::table('accurate_po')->insert(['POID' => $id, 'PONO' => "BL/D/26/IX/{$id}", 'PODATE' => '2026-09-01', 'CLOSED' => $closed, 'POAMOUNT' => 0]);
        $line = fn ($id, $qty, $recv) => DB::table('accurate_podet')->insert(['POID' => $id, 'SEQ' => 0, 'ITEMNO' => 'X.1', 'QUANTITY' => $qty, 'QTYRECV' => $recv, 'UNITPRICE' => 0, 'ITEMUNIT' => 'PCS']);
        $po(1, 1); $line(1, 10, 4);   // closed after a partial receipt
        $po(2, 0); $line(2, 10, 4);   // still waiting for the rest
        $po(3, 1); $line(3, 10, 10);  // fully received

        \App\Models\Site::factory()->create();
        \App\Models\Unit::factory()->create(['code' => 'PCS']);
        Item::factory()->create(['code' => 'X.1']);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate');
        $this->assertSame(201, $res->status(), json_encode(\App\Models\SyncLog::where('status', 'FAILED')->pluck('message')));

        $status = \App\Models\PurchaseOrder::pluck('status', 'accurate_po_id');
        $this->assertSame('CLOSED', $status[1]);
        $this->assertSame('PARTIAL_RECEIVED', $status[2]);
        $this->assertSame('RECEIVED', $status[3]);

        // Accurate revises PO 2: its line is removed, another one added → STOCKWISE follows (no "ghost" line left on order)
        DB::table('accurate_podet')->where('POID', 2)->delete();
        DB::table('accurate_podet')->insert(['POID' => 2, 'SEQ' => 1, 'ITEMNO' => 'X.1', 'QUANTITY' => 0.75, 'QTYRECV' => 0, 'UNITPRICE' => 422.433, 'ITEMUNIT' => 'PCS']);
        $this->postJson('/api/sync/accurate')->assertStatus(201);

        $lines = \App\Models\PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q->where('accurate_po_id', 2))->get();
        $this->assertSame([1], $lines->pluck('accurate_seq')->all(), 'the removed line is gone');
        $this->assertEquals([0.75, 422.433], [$lines[0]->qty, $lines[0]->unit_price], '4 decimals kept, as in Accurate');
        $log = \App\Models\SyncLog::where('entity', 'po')->where('action', 'UPDATE')->latest('id')->first();
        $this->assertContains('dihapus di Accurate', collect($log->new_data['_lines'])->pluck('to')->all());
    }

    private function snapshotDefaults(): array
    {
        return [
            'analysis_run_id' => InventoryAnalysisRun::value('id'), 'actual' => 0, 'reserved' => 0, 'available' => 0,
            'stock_known' => true, 'safety_stock' => 0, 'lead_time_days' => 0, 'selisih' => 0, 'deficit' => 0,
            'priority_score' => 0, 'priority_level' => 'LOW', 'recommendation' => '-', 'recommended_qty' => 0,
            'computed_at' => now(),
        ];
    }
}
