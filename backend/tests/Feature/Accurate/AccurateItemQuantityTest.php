<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Services\Accurate\AccurateItemQuantity;
use App\Services\Accurate\StagingTableWriter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Master Barang stock = Accurate's own GETITEMQUANTITY / GETITEMBAL (ITEMBALANCE +
 * ITEMHIST), qty on order = GETITEMONORDER — not ITEM.QUANTITY / ITEM.ONORDER,
 * which Accurate does not keep up to date (user report 2026-10-04). And decimals
 * reach the mirror exactly (NUMERIC(18,4) was mirrored as BIGINT).
 */
class AccurateItemQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('accurate_itembalance', function ($t) {
            $t->string('ITEMNO');
            $t->integer('GLYEAR');
            $t->decimal('OPENINGBAL', 18, 4)->nullable();
            for ($m = 1; $m <= 12; $m++) {
                $t->decimal('AMOUNT'.$m, 18, 4)->nullable();
            }
        });
        Schema::create('accurate_itemhist', function ($t) {
            $t->string('ITEMNO');
            $t->date('TXDATE')->nullable();
            $t->decimal('QUANTITY', 18, 4)->nullable();
        });
    }

    private function balance(string $item, int $year, float $opening, array $amounts = []): void
    {
        $row = ['ITEMNO' => $item, 'GLYEAR' => $year, 'OPENINGBAL' => $opening];
        foreach ($amounts as $m => $v) {
            $row['AMOUNT'.$m] = $v;
        }
        DB::table('accurate_itembalance')->insert($row);
    }

    private function hist(string $item, string $date, float $qty): void
    {
        DB::table('accurate_itemhist')->insert(['ITEMNO' => $item, 'TXDATE' => $date, 'QUANTITY' => $qty]);
    }

    public function test_quantity_follows_getitembal(): void
    {
        $oct4 = Carbon::parse('2026-10-04', 'Asia/Jakarta');

        // this year's balance: opening + Jan..Sep amounts + October history up to today
        $this->balance('AUT.0005', 2026, 2, [1 => 1, 5 => -1, 9 => 3, 10 => 99 /* October amount: not used, history is */]);
        $this->hist('AUT.0005', '2026-09-30', 50);   // September: already in AMOUNT9
        $this->hist('AUT.0005', '2026-10-01', 0.75);
        $this->hist('AUT.0005', '2026-10-04', -1);
        $this->hist('AUT.0005', '2026-10-05', 10);   // after today: not yet

        // no balance row this year → last year's balance on 31-12 (this year's history is NOT added — Accurate's rule)
        $this->balance('OFN.0009', 2025, 60, [11 => 6]);
        $this->hist('OFN.0009', '2025-12-10', 4);
        $this->hist('OFN.0009', '2026-03-01', -100);

        // never in ITEMBALANCE: only this month's history counts
        $this->hist('SSP.0001', '2026-09-15', 7);
        $this->hist('SSP.0001', '2026-10-02', 2.5);

        $qty = app(AccurateItemQuantity::class)->all($oct4);

        $this->assertSame(4.75, $qty['AUT.0005']); // 2 + 1 - 1 + 3 + 0.75 - 1
        $this->assertSame(70.0, $qty['OFN.0009']); // 60 + 6 + 4
        $this->assertSame(2.5, $qty['SSP.0001']);
    }

    public function test_sync_takes_master_barang_qty_from_accurate_balance_not_item_quantity(): void
    {
        Schema::create('accurate_item', function ($t) {
            foreach (['ITEMNO', 'ITEMDESCRIPTION', 'UNIT1', 'PARENTITEM'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('SUSPENDED')->nullable();
            $t->decimal('QUANTITY', 18, 4)->nullable();
            $t->decimal('ONORDER', 18, 4)->nullable();
        });
        DB::table('accurate_item')->insert([
            ['ITEMNO' => 'OFN.0009', 'ITEMDESCRIPTION' => 'MAP', 'UNIT1' => 'PCS', 'QUANTITY' => 0, 'ONORDER' => 0],
            ['ITEMNO' => 'OFN.0028', 'ITEMDESCRIPTION' => 'PULPEN', 'UNIT1' => 'PCS', 'QUANTITY' => 9, 'ONORDER' => 0],
        ]);
        $this->balance('OFN.0009', (int) now('Asia/Jakarta')->year, 66);
        \App\Models\Unit::factory()->create(['code' => 'PCS']);
        Process::fake();
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated()->assertJsonPath('data.error_records', 0)->assertJsonPath('data.inserted_records', 2);

        $this->assertEquals(66, Item::where('code', 'OFN.0009')->value('accurate_qty_onhand'), 'ITEM.QUANTITY said 0');
        $this->assertEquals(0, Item::where('code', 'OFN.0028')->value('accurate_qty_onhand'), 'no balance / history in Accurate = 0, not ITEM.QUANTITY 9');
    }

    public function test_qty_on_order_follows_getitemonorder(): void
    {
        Schema::create('accurate_po', function ($t) {
            $t->integer('POID');
            $t->date('PODATE')->nullable();
        });
        Schema::create('accurate_podet', function ($t) {
            $t->integer('POID');
            $t->string('ITEMNO');
            $t->decimal('QUANTITY', 18, 4);
            $t->decimal('QTYRECV', 18, 4);
            $t->decimal('UNITRATIO', 18, 4);
            $t->integer('CLOSED');
        });
        DB::table('accurate_po')->insert([['POID' => 1, 'PODATE' => '2026-10-03'], ['POID' => 2, 'PODATE' => '2026-10-09']]);
        DB::table('accurate_podet')->insert([
            ['POID' => 1, 'ITEMNO' => 'OFN.0028', 'QUANTITY' => 150, 'QTYRECV' => 0, 'UNITRATIO' => 1, 'CLOSED' => 0],
            ['POID' => 1, 'ITEMNO' => 'OFN.0028', 'QUANTITY' => 10, 'QTYRECV' => 4, 'UNITRATIO' => 0.5, 'CLOSED' => 0],
            ['POID' => 1, 'ITEMNO' => 'OFN.0028', 'QUANTITY' => 10, 'QTYRECV' => 0, 'UNITRATIO' => 1, 'CLOSED' => 1],  // closed
            ['POID' => 1, 'ITEMNO' => 'OFN.0028', 'QUANTITY' => 5, 'QTYRECV' => 5, 'UNITRATIO' => 1, 'CLOSED' => 0],   // fully received
            ['POID' => 2, 'ITEMNO' => 'OFN.0028', 'QUANTITY' => 10, 'QTYRECV' => 0, 'UNITRATIO' => 1, 'CLOSED' => 0],  // PO dated later
        ]);

        $onOrder = app(AccurateItemQuantity::class)->onOrder(Carbon::parse('2026-10-04'));

        $this->assertSame(['OFN.0028' => 153.0], $onOrder); // 150 + (10-4)×0.5
    }

    public function test_decimal_columns_are_mirrored_exactly(): void
    {
        $writer = app(StagingTableWriter::class);
        $writer->createTable('ITEMHIST', [
            ['name' => 'ITEMNO', 'type' => 'VARCHAR', 'length' => 30, 'nullable' => true],
            ['name' => 'QUANTITY', 'type' => 'DECIMAL', 'length' => 18, 'scale' => 4, 'nullable' => true],
        ]);
        $writer->insertRows('ITEMHIST', [['ITEMNO' => 'BSP.0075', 'QUANTITY' => '0.7500'], ['ITEMNO' => 'BSP.0080', 'QUANTITY' => '4.0500']]);

        $this->assertSame([0.75, 4.05], DB::table('accurate_itemhist')->orderBy('ITEMNO')->pluck('QUANTITY')->map(fn ($v) => (float) $v)->all());

        $this->expectException(\InvalidArgumentException::class);
        $writer->createTable('ITEMHIST', [['name' => 'Q', 'type' => 'DECIMAL', 'length' => 4, 'scale' => 6, 'nullable' => true]]);
    }
}
