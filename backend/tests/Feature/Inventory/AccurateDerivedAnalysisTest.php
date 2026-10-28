<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory;
use App\Models\InventorySnapshot;
use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Models\Npbg;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Ri;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\AccurateDerivedAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock / lead time / Safety Stock derived from Accurate data (no Excel), then
 * the existing engine: SS = ROUNDUP(2.33 × avg_usage_3m × LT/30).
 */
class AccurateDerivedAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wh = Warehouse::factory()->create();
        Unit::factory()->create(['code' => 'PCS']);
    }

    private function item(string $code, float $accurateQty): Item
    {
        return Item::factory()->create(['code' => $code, 'accurate_qty_onhand' => $accurateQty, 'is_active' => true]);
    }

    private function usage(string $code, float $qty, int $daysAgo): void
    {
        Npbg::create([
            'accurate_arinvoice_id' => random_int(1, 9_999_999), 'accurate_seq' => 1,
            'no_npbg' => 'X/'.uniqid(), 'tgl_npbg' => now()->subDays($daysAgo)->toDateString(),
            'kode_barang' => $code, 'deskripsi_barang' => $code, 'kuantitas' => $qty, 'satuan' => 'PCS',
        ]);
    }

    private function purchase(Item $item, int $poDaysAgo, int $receivedDaysAgo): void
    {
        $po = PurchaseOrder::factory()->create(['date' => now()->subDays($poDaysAgo)->toDateString()]);
        $line = PurchaseOrderItem::forceCreate([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description_raw' => $item->code,
            'qty' => 1, 'unit_id' => $item->unit_id, 'unit_price' => 0, 'line_total' => 0,
        ]);
        Ri::forceCreate([
            'accurate_apinvoice_id' => random_int(1, 9_999_999), 'accurate_seq' => 1, 'accurate_po_item_id' => $line->id,
            'no_ri' => 'RI/'.uniqid(), 'tgl_ri' => now()->subDays($receivedDaysAgo)->toDateString(),
            'kode_barang' => $item->code, 'deskripsi_barang' => $item->code, 'kuantitas' => 1, 'satuan' => 'PCS',
        ]);
    }

    public function test_full_chain_from_accurate_data_to_dashboard_status(): void
    {
        // A: stock 2, used 90 in 3 months (avg 30/month), lead times 10 & 20 days -> median 15
        //    demand = 30 × 15/30 = 15 -> SS = ROUNDUP(2.33 × 15) = 35 -> 2 < 35 -> TIDAK_AMAN
        $a = $this->item('AAA.0001', 2);
        $this->usage('AAA.0001', 90, 20);
        $this->purchase($a, 40, 30);
        $this->purchase($a, 60, 40);

        // B: stock 500, same usage & lead time -> SS 35 -> AMAN
        $b = $this->item('BBB.0001', 500);
        $this->usage('BBB.0001', 90, 20);
        $this->purchase($b, 40, 25);

        // C: stock 0, never used -> SS 0 -> BEP
        $c = $this->item('CCC.0001', 0);

        // D: used but never purchased -> no lead time (not guessed) -> SS 0; stock 5 -> AMAN
        $d = $this->item('DDD.0001', 5);
        $this->usage('DDD.0001', 30, 10);

        $report = app(AccurateDerivedAnalysis::class)->run();

        // 1. opening balances from Accurate qty
        $this->assertSame(4, Inventory::count());
        $this->assertEquals(2, Inventory::where('item_id', $a->id)->value('actual_qty'));
        $this->assertTrue((bool) Inventory::where('item_id', $c->id)->value('stock_known'));

        // 2. lead time = median PO->RI days
        $ssA = ItemSafetyStock::where('item_id', $a->id)->first();
        $this->assertSame(15, $ssA->lead_time_days);
        $this->assertEquals(30, $ssA->avg_usage_3m);
        // 3. formula
        $this->assertEquals(35, $ssA->safety_stock);
        $this->assertSame('ACCURATE', $ssA->source_category);
        $this->assertNull(ItemSafetyStock::where('item_id', $d->id)->value('lead_time_days'));
        $this->assertEquals(0, ItemSafetyStock::where('item_id', $d->id)->value('safety_stock'));
        $this->assertSame(1, $report['usage_items_without_lead_time']);

        // 4. analysis snapshots = what the dashboard reads
        $status = InventorySnapshot::pluck('status', 'item_id');
        $this->assertSame('TIDAK_AMAN', $status[$a->id]);
        $this->assertSame('AMAN', $status[$b->id]);
        $this->assertSame('BEP', $status[$c->id]);
        $this->assertSame('AMAN', $status[$d->id]);
    }

    public function test_is_idempotent_stock_follows_accurate_and_manual_safety_stock_is_kept(): void
    {
        $a = $this->item('AAA.0001', 10);
        $this->usage('AAA.0001', 90, 20);
        $this->purchase($a, 40, 30);
        $m = $this->item('MMM.0001', 3);
        $this->usage('MMM.0001', 60, 20);
        ItemSafetyStock::forceCreate([
            'item_id' => $m->id, 'source_category' => 'MANUAL', 'avg_usage_3m' => 1, 'lead_time_days' => 7,
            'safety_stock' => 99, 'min_pr' => 100, 'is_effective' => true, 'needs_review' => false,
        ]);

        app(AccurateDerivedAnalysis::class)->run();
        // Accurate qty changes (e.g. an NPBG in Accurate) -> STOCKWISE follows on the next run
        $a->update(['accurate_qty_onhand' => 6]);
        $report = app(AccurateDerivedAnalysis::class)->run();

        $this->assertSame(2, Inventory::count(), 'no second opening balance');
        $this->assertEquals(6, Inventory::where('item_id', $a->id)->value('actual_qty'));
        $this->assertSame(1, $report['accurate_follow_changed']);
        $mv = \App\Models\StockMovement::where('item_id', $a->id)->where('movement_type', 'ACCURATE_SYNC')->first();
        $this->assertNotNull($mv, 'change is an audited ledger movement');
        $this->assertEquals(10, $mv->actual_before);
        $this->assertEquals(6, $mv->actual_after);
        $this->assertNull(Inventory::where('item_id', $a->id)->value('last_counted_at'), 'not a physical count');

        // unchanged Accurate qty -> no new movement
        $this->assertSame(0, app(AccurateDerivedAnalysis::class)->run()['accurate_follow_changed']);

        $this->assertSame(1, ItemSafetyStock::where('item_id', $a->id)->count(), 'ACCURATE row updated, not duplicated');
        $this->assertEquals(99, ItemSafetyStock::where('item_id', $m->id)->value('safety_stock'), 'manual row kept');
        $this->assertSame(1, ItemSafetyStock::where('item_id', $m->id)->count());
    }

    public function test_stock_reserved_by_stockwise_is_never_released(): void
    {
        $a = $this->item('AAA.0001', 10);
        app(AccurateDerivedAnalysis::class)->run();
        Inventory::where('item_id', $a->id)->update(['reserved_qty' => 8]);
        $a->update(['accurate_qty_onhand' => 5]);   // below the 8 reserved

        $report = app(AccurateDerivedAnalysis::class)->run();

        $this->assertSame(1, $report['accurate_follow_blocked_by_reservation']);
        $this->assertEquals(10, Inventory::where('item_id', $a->id)->value('actual_qty'));
    }

    public function test_following_accurate_can_be_switched_off(): void
    {
        config(['stockwise.stock_follows_accurate' => false]);
        $a = $this->item('AAA.0001', 10);
        app(AccurateDerivedAnalysis::class)->run();
        $a->update(['accurate_qty_onhand' => 3]);

        app(AccurateDerivedAnalysis::class)->run();

        $this->assertEquals(10, Inventory::where('item_id', $a->id)->value('actual_qty'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $a = $this->item('AAA.0001', 10);
        $this->usage('AAA.0001', 90, 20);

        $report = app(AccurateDerivedAnalysis::class)->run(dryRun: true);

        $this->assertSame(1, $report['opening_balance_items']);
        $this->assertSame(0, Inventory::count());
        $this->assertSame(0, ItemSafetyStock::count());
        $this->assertSame(0, InventorySnapshot::count());
    }
}
