<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\Npbg;
use App\Models\PpbDraft;
use App\Models\Warehouse;
use App\Services\Accurate\StockwisePurge;
use App\Services\Inventory\StockLedgerService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Accurate-only mode: after a sync, whatever was made in STOCKWISE is gone (Kartu Stock and tracking stay). */
class StockwisePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_stockwise_data_goes_accurate_data_and_kartu_stock_stay(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['stockwise.stockwise_ledger_since' => now()->subDay()->toDateTimeString(), 'stockwise.accurate_only' => true]);
        $wh = Warehouse::factory()->create();
        // an item from Accurate with 5 in stock
        $item = Item::factory()->create(['code' => 'ACC.1', 'description' => 'DARI ACCURATE', 'accurate_synced_at' => now(), 'accurate_qty_onhand' => 5]);
        app(StockLedgerService::class)->openingBalance($item->id, $wh->id, 5);
        $accNpbg = Npbg::create(['no_npbg' => 'NA/26/X/001', 'tgl_npbg' => now()->toDateString(), 'deskripsi_barang' => 'X', 'kuantitas' => 1, 'accurate_arinvoice_id' => 1, 'accurate_seq' => 1]);

        // a Request Barang given from stock (NPBG + stock out), a master item made here, a Restock draft
        $this->actingAsRole('karyawan');
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'barang', 'qty' => 2]]])->json('data');
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/items', ['code' => 'SW.1', 'description' => 'BUATAN STOCKWISE'])->assertCreated();
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$req['lines'][0]['id'] => ['item_id' => $item->id]]])->assertOk();
        $this->actingAsRole('direktur');
        $this->postJson("/api/item-requests/{$req['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.status', 'DISIAPKAN');
        // prepared, not handed over: the 2 are held, the stock is still 5
        $this->assertSame([5.0, 2.0], [(float) DB::table('inventory')->where('item_id', $item->id)->sum('actual_qty'), (float) DB::table('inventory')->where('item_id', $item->id)->sum('reserved_qty')]);
        $kept = Item::factory()->create(['code' => 'SW.2', 'description' => 'DIPAKAI KARTU STOCK']);
        DB::table('stock_cards')->insert(['item_id' => $kept->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['request_barang' => 1, 'npbg_manual' => 1], array_intersect_key(app(StockwisePurge::class)->run(true), ['request_barang' => 1, 'npbg_manual' => 1]));
        $removed = app(StockwisePurge::class)->run();

        $this->assertSame([0, 1, 0], [ItemRequest::count(), Npbg::count(), PpbDraft::count()]);
        $this->assertTrue(Npbg::whereKey($accNpbg->id)->exists(), 'Accurate data stays');
        $this->assertSame([5.0, 0.0], [(float) DB::table('inventory')->where('item_id', $item->id)->sum('actual_qty'), (float) DB::table('inventory')->where('item_id', $item->id)->sum('reserved_qty')], 'stock back to Accurate, nothing held');
        $this->assertSame([false, true], [Item::where('code', 'SW.1')->exists(), Item::where('code', 'SW.2')->exists()], 'a master item used by Kartu Stock stays');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'STOCK_OUT')->count());
        // only held, never taken out: the stock is Accurate's already, nothing to align
        $this->assertSame(0, $removed['stok_disamakan']);
    }
}
