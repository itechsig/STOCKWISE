<?php

namespace Tests\Feature\Accurate;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\SyncBatch;
use App\Models\Unit;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests POST /api/sync/accurate — the manual/admin "re-run" trigger, which
 * reprocesses whatever is CURRENTLY in the accurate_* staging tables
 * (AccurateSyncService::run() -> finishFromStaging()). Exercises the same
 * upsert logic, idempotency, validation, error handling, permissions and
 * sync_logs the Agent's push-triggered complete() also drives — see
 * tests/Feature/Agent/AccurateIngestTest.php for the push/ingest side
 * (staging table creation from a payload, ability auth, fail()).
 *
 * Fixture `accurate_item` table created in the test's own SQLite connection,
 * same column names/shapes as the real Accurate ITEM table.
 */
class AccurateSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture staging table standing in for the real MySQL accurate_item
        // mirror (same column names/shapes as the real Accurate ITEM table).
        if (! Schema::hasTable('accurate_item')) {
            Schema::create('accurate_item', function ($table) {
                $table->string('ITEMNO')->nullable();
                $table->string('ITEMDESCRIPTION')->nullable();
                $table->string('UNIT1')->nullable();
                $table->boolean('SUSPENDED')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->decimal('ONORDER', 14, 2)->nullable();
                $table->string('PARENTITEM')->nullable();
            });
        }
    }

    protected function seedAccurateItem(array $row): void
    {
        DB::table('accurate_item')->insert(array_merge([
            'ITEMNO' => null, 'ITEMDESCRIPTION' => null, 'UNIT1' => null,
            'SUSPENDED' => false, 'QUANTITY' => 0, 'ONORDER' => 0, 'PARENTITEM' => null,
        ], $row));
    }

    public function test_trigger_requires_permission(): void
    {
        $this->postJson('/api/sync/accurate')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->postJson('/api/sync/accurate')->assertForbidden();
    }

    public function test_new_accurate_item_is_inserted(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem([
            'ITEMNO' => 'NEW.0001', 'ITEMDESCRIPTION' => 'Oxygen Tank', 'UNIT1' => 'PCS',
            'QUANTITY' => 100, 'ONORDER' => 0,
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.inserted_records', 1)
            ->assertJsonPath('data.updated_records', 0)
            ->assertJsonPath('data.error_records', 0);

        $item = Item::where('code', 'NEW.0001')->firstOrFail();
        $this->assertSame('Oxygen Tank', $item->description);
        $this->assertSame('accurate', $item->source);
        $this->assertEquals(100, $item->accurate_qty_onhand);
        $this->assertNotNull($item->accurate_synced_at);

        $log = DB::table('sync_logs')->where('source_id', 'NEW.0001')->first();
        $this->assertSame('INSERT', $log->action);
        $this->assertSame('SUCCESS', $log->status);
    }

    public function test_item_with_no_inventory_row_gets_opening_balance_seeded_from_accurate_qty(): void
    {
        // Per explicit user instruction: don't wait for a physical Stock
        // Opname to resolve stock_known=false — an item Accurate already
        // shows real stock for must not sit "unknown" (and be treated as 0,
        // a false TIDAK_AMAN alert) indefinitely.
        // Opt-in since 2026-09-26 (config stockwise.sync_seed_opening_balance).
        config(['stockwise.sync_seed_opening_balance' => true]);
        Unit::factory()->create(['code' => 'PCS']);
        Warehouse::factory()->create();
        $this->seedAccurateItem(['ITEMNO' => 'AUT.0001', 'ITEMDESCRIPTION' => 'BOLT M10', 'UNIT1' => 'PCS', 'QUANTITY' => 42]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $item = Item::where('code', 'AUT.0001')->firstOrFail();
        $inv = Inventory::where('item_id', $item->id)->firstOrFail();
        $this->assertEquals(42, $inv->actual_qty);
        $this->assertEquals(0, $inv->reserved_qty);
        $this->assertTrue((bool) $inv->stock_known);
        $this->assertSame('OPENING_BALANCE', StockMovement::where('item_id', $item->id)->value('movement_type'));
    }

    /** User decision 2026-09-26 (no Excel any more): every sync ends with
     *  AccurateDerivedAnalysis, which seeds the opening balance from Accurate qty. */
    public function test_sync_seeds_opening_balance_from_accurate_qty_via_post_sync_analysis(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        Warehouse::factory()->create();
        $this->seedAccurateItem(['ITEMNO' => 'AUT.0002', 'ITEMDESCRIPTION' => 'BOLT M12', 'UNIT1' => 'PCS', 'QUANTITY' => 7]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $item = Item::where('code', 'AUT.0002')->firstOrFail();
        $this->assertEquals(7, Inventory::where('item_id', $item->id)->value('actual_qty'));
        $this->assertSame('OPENING_BALANCE', StockMovement::where('item_id', $item->id)->value('movement_type'));
    }

    public function test_item_already_tracked_by_stockwise_is_never_overwritten_by_a_later_accurate_qty(): void
    {
        // Only when stock-follows-Accurate is switched off (default ON since 2026-09-26,
        // see AccurateDerivedAnalysisTest for the ON behaviour).
        config(['stockwise.stock_follows_accurate' => false]);
        // Once Stockwise's own ledger exists for an item (any real
        // operation — reserve/pickup/opname/etc.), Accurate's qty stays
        // reference-only from then on, same as every other Accurate sync in
        // this class — the opening-balance seed is a ONE-TIME backfill, not
        // an ongoing overwrite that would erase real reservations later.
        Unit::factory()->create(['code' => 'PCS']);
        $warehouse = Warehouse::factory()->create();
        $this->seedAccurateItem(['ITEMNO' => 'AUT.0002', 'ITEMDESCRIPTION' => 'NUT M10', 'UNIT1' => 'PCS', 'QUANTITY' => 10]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();
        $item = Item::where('code', 'AUT.0002')->firstOrFail();

        // Stockwise's own operation moves actual stock to 5 (e.g. a pickup) —
        // this must survive the next Accurate sync untouched.
        app(\App\Services\Inventory\StockLedgerService::class)->record([
            'type' => 'STOCK_ADJUSTMENT', 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'absolute' => 5,
        ]);

        DB::table('accurate_item')->where('ITEMNO', 'AUT.0002')->update(['QUANTITY' => 999]);
        $this->postJson('/api/sync/accurate')->assertCreated();

        $this->assertEquals(5, Inventory::where('item_id', $item->id)->value('actual_qty'));
        $this->assertEquals(999, Item::where('code', 'AUT.0002')->value('accurate_qty_onhand'));
    }

    public function test_second_sync_updates_existing_item_without_duplicating(): void
    {
        // §28 of the brief: BRG001 OXYGEN stock 100 -> sync -> 150 -> sync
        // again must UPDATE the one row, never create a second one.
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem([
            'ITEMNO' => 'BRG.0001', 'ITEMDESCRIPTION' => 'OXYGEN', 'UNIT1' => 'PCS', 'QUANTITY' => 100,
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $this->assertSame(1, Item::where('code', 'BRG.0001')->count());
        $this->assertEquals(100, Item::where('code', 'BRG.0001')->value('accurate_qty_onhand'));

        DB::table('accurate_item')->where('ITEMNO', 'BRG.0001')->update(['QUANTITY' => 150]);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.updated_records', 1)
            ->assertJsonPath('data.inserted_records', 0);

        $this->assertSame(1, Item::where('code', 'BRG.0001')->count(), 'sync must not create a duplicate row');
        $this->assertEquals(150, Item::where('code', 'BRG.0001')->value('accurate_qty_onhand'));
    }

    public function test_unchanged_item_is_skipped_on_repeat_sync(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'BRG.0002', 'ITEMDESCRIPTION' => 'NITROGEN', 'UNIT1' => 'PCS', 'QUANTITY' => 50]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0)
            ->assertJsonPath('data.updated_records', 0);
    }

    public function test_category_placeholder_node_without_unit_is_skipped_not_inserted(): void
    {
        // No UNIT1 — Accurate's PARENTITEM category-tree nodes, not real
        // products (see docs/ACCURATE_MAPPING.md).
        $this->seedAccurateItem(['ITEMNO' => 'CAT.01', 'ITEMDESCRIPTION' => 'SOME CATEGORY', 'UNIT1' => null]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0);
        $this->assertSame(0, Item::where('code', 'CAT.01')->count());
    }

    public function test_product_pattern_is_authoritative_even_when_unit_is_present(): void
    {
        // §3 of the brief: ITEMNO shape (3 letters + "." + 4+ digits) is the
        // authoritative product/category test — a category-shaped code must
        // never become a Master Barang row even if it happens to carry a
        // UNIT1 (a real data-quality anomaly, not something to trust blindly).
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'ZZZ.01', 'ITEMDESCRIPTION' => 'Anomali', 'UNIT1' => 'PCS', 'QUANTITY' => 5]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.skipped_records', 1)
            ->assertJsonPath('data.inserted_records', 0);
        $this->assertSame(0, Item::where('code', 'ZZZ.01')->count());
    }

    public function test_category_hierarchy_resolved_via_itemdescription_of_parentitem_chain(): void
    {
        // §4-5 of the brief's own worked example, reproduced with fixture
        // data: category nodes' ITEMDESCRIPTION (not their ITEMNO code)
        // becomes Kategori Anak 1/2/3. No "Kategori Induk" is asserted here —
        // verified against the real GDB that the 0-dot root node never
        // exists in this company's data, see docs/accurate-database-analysis.md §12.
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'AAA.01', 'ITEMDESCRIPTION' => 'AUTOMOTIVE WHEELS & TIRES', 'PARENTITEM' => null]);
        $this->seedAccurateItem(['ITEMNO' => 'AAA.01.01', 'ITEMDESCRIPTION' => 'BAN LUAR (TIRES)', 'PARENTITEM' => 'AAA.01']);
        $this->seedAccurateItem(['ITEMNO' => 'AAA.01.01.01', 'ITEMDESCRIPTION' => 'BAN LUAR BENANG (NYLON TIRES)', 'PARENTITEM' => 'AAA.01.01']);
        $this->seedAccurateItem([
            'ITEMNO' => 'AAA.0001', 'ITEMDESCRIPTION' => 'BOLT M10 X 50', 'UNIT1' => 'PCS',
            'QUANTITY' => 125, 'PARENTITEM' => 'AAA.01.01.01',
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        // 4 rows read, only 1 is a real product; the 3 category nodes are
        // skipped as Master Barang rows but still consulted for the hierarchy.
        $res->assertJsonPath('data.total_records', 4)
            ->assertJsonPath('data.inserted_records', 1)
            ->assertJsonPath('data.skipped_records', 3);

        $item = Item::where('code', 'AAA.0001')->firstOrFail();
        $this->assertSame('AUTOMOTIVE WHEELS & TIRES', $item->accurate_category_anak_1);
        $this->assertSame('BAN LUAR (TIRES)', $item->accurate_category_anak_2);
        $this->assertSame('BAN LUAR BENANG (NYLON TIRES)', $item->accurate_category_anak_3);
        $this->assertEquals(125, $item->accurate_qty_onhand);

        $this->getJson("/api/items/{$item->id}")->assertOk()
            ->assertJsonPath('data.category_breakdown.induk', null)
            ->assertJsonPath('data.category_breakdown.anak_1', 'AUTOMOTIVE WHEELS & TIRES')
            ->assertJsonPath('data.category_breakdown.anak_2', 'BAN LUAR (TIRES)')
            ->assertJsonPath('data.category_breakdown.anak_3', 'BAN LUAR BENANG (NYLON TIRES)');
    }

    public function test_category_hierarchy_shallower_than_three_levels_leaves_deeper_slots_null(): void
    {
        // Real GDB finding: chain depth varies per product (1, 2, or 3 real
        // ancestors) — a product one level deep must not have Anak 2/3 guessed.
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'BBB.01', 'ITEMDESCRIPTION' => 'ASSET TOOLS', 'PARENTITEM' => null]);
        $this->seedAccurateItem([
            'ITEMNO' => 'BBB.0001', 'ITEMDESCRIPTION' => 'OBENG PLUS', 'UNIT1' => 'PCS', 'PARENTITEM' => 'BBB.01',
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 1);

        $item = Item::where('code', 'BBB.0001')->firstOrFail();
        $this->assertSame('ASSET TOOLS', $item->accurate_category_anak_1);
        $this->assertNull($item->accurate_category_anak_2);
        $this->assertNull($item->accurate_category_anak_3);
    }

    public function test_row_with_empty_itemno_is_skipped_safely(): void
    {
        $this->seedAccurateItem(['ITEMNO' => '', 'ITEMDESCRIPTION' => 'Broken row', 'UNIT1' => 'PCS']);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.skipped_records', 1);
    }

    public function test_rerun_with_no_staged_rows_succeeds_with_zero_counts(): void
    {
        // run() never talks to Firebird/the Agent — it only reprocesses
        // whatever is already staged. An empty accurate_item table (e.g.
        // nothing has ever been pushed yet) is a legitimate, non-error state.
        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();

        $res->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.total_records', 0);
    }

    public function test_second_manual_rerun_returns_the_in_progress_batch_instead_of_starting_another(): void
    {
        // Sync lock (AccurateSyncService::startBatch()): a RUNNING batch from
        // one call must be reused, not duplicated, by a concurrent call.
        $batch = SyncBatch::create([
            'sync_code' => 'SYNC-TEST-LOCK', 'source' => 'accurate',
            'started_at' => now(), 'status' => 'RUNNING', 'current_step' => 'items',
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertStatus(202); // still running -> 202 (processing now happens in the background)

        $res->assertJsonPath('data.id', $batch->id)
            ->assertJsonPath('data.status', 'RUNNING');
    }

    public function test_sync_history_and_status_endpoints(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        $this->seedAccurateItem(['ITEMNO' => 'HHH.0001', 'ITEMDESCRIPTION' => 'Test', 'UNIT1' => 'PCS']);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();

        $this->getJson('/api/sync/status')->assertOk()->assertJsonPath('data.status', 'SUCCESS');

        $history = $this->getJson('/api/sync/history')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($history->json('data')));

        $batch = SyncBatch::first();
        $this->getJson("/api/sync/history/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $batch->id)
            ->assertJsonCount(1, 'data.logs');
    }
}
