<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Models\Site;
use App\Models\StockOpname;
use App\Models\SyncBatch;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 2026-09-26 sync reliability/performance fixes:
 * - stock opname no longer fails for every row when STOCKWISE has no warehouse
 * - unchanged (SKIP) rows are counted, not logged one by one
 * - stale-batch detection uses a heartbeat, not the batch's age
 * - Agent complete() is idempotent and pollable
 */
class AccurateSyncReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accurate_item', function ($t) {
            $t->string('ITEMNO')->nullable();
            $t->string('ITEMDESCRIPTION')->nullable();
            $t->string('UNIT1')->nullable();
            $t->integer('SUSPENDED')->nullable();
            $t->decimal('QUANTITY', 18, 4)->nullable();
            $t->decimal('ONORDER', 18, 4)->nullable();
            $t->string('PARENTITEM')->nullable();
        });
        Schema::create('accurate_warehs', function ($t) {
            $t->integer('WAREHOUSEID');
            $t->string('NAME')->nullable();
        });
        Schema::create('accurate_itemadj', function ($t) {
            $t->integer('ITEMADJID');
            $t->string('ADJNO')->nullable();
            $t->date('ADJDATE')->nullable();
            $t->integer('ADJCHECK')->nullable();
            $t->string('DESCRIPTION')->nullable();
            $t->integer('WAREHOUSEID')->nullable();
        });
        Schema::create('accurate_itadjdet', function ($t) {
            $t->integer('ITEMADJID');
            $t->integer('SEQ');
            $t->string('ITEMNO')->nullable();
            $t->decimal('NEWQTY', 18, 4)->nullable();
            $t->decimal('CURRENTQTY', 18, 4)->nullable();
        });

        Unit::factory()->create(['code' => 'PCS']);
        Site::query()->firstOrCreate(['code' => 'SIG-SDA'], ['name' => 'SIG Sidoarjo']);
        foreach (['AUT.0001', 'AUT.0002', 'AUT.0003'] as $code) {
            DB::table('accurate_item')->insert([
                'ITEMNO' => $code, 'ITEMDESCRIPTION' => "Barang {$code}", 'UNIT1' => 'PCS',
                'SUSPENDED' => 0, 'QUANTITY' => 5, 'ONORDER' => 0, 'PARENTITEM' => null,
            ]);
        }
        DB::table('accurate_warehs')->insert(['WAREHOUSEID' => 0, 'NAME' => 'CENTRE']);
        DB::table('accurate_itemadj')->insert([
            'ITEMADJID' => 77, 'ADJNO' => 'ADJ-001', 'ADJDATE' => '2026-09-10', 'ADJCHECK' => 1,
            'DESCRIPTION' => 'STOK OPNAME TGL 10.09.2026', 'WAREHOUSEID' => 0,
        ]);
        DB::table('accurate_itadjdet')->insert([
            'ITEMADJID' => 77, 'SEQ' => 1, 'ITEMNO' => 'AUT.0001', 'NEWQTY' => 4, 'CURRENTQTY' => 5,
        ]);
    }

    private function sync(): SyncBatch
    {
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/sync/accurate')->assertCreated()->json('data.id');

        return SyncBatch::findOrFail($id);
    }

    public function test_stock_opname_uses_the_accurate_warehouse_when_stockwise_has_none(): void
    {
        $this->assertSame(0, Warehouse::count());

        $batch = $this->sync();

        $this->assertSame(0, $batch->error_records, 'no row may fail just because STOCKWISE had no warehouse');
        $this->assertSame('SUCCESS', $batch->status);
        $warehouse = Warehouse::where('code', 'ACC-CENTRE')->firstOrFail();
        $this->assertSame($warehouse->id, StockOpname::where('accurate_itemadj_id', 77)->value('warehouse_id'));

        // re-running never creates a second warehouse
        $this->sync();
        $this->assertSame(1, Warehouse::count());
    }

    public function test_unchanged_rows_are_counted_but_not_logged(): void
    {
        $this->sync();
        $second = $this->sync();

        $this->assertSame(0, $second->inserted_records + $second->updated_records);
        $this->assertGreaterThanOrEqual(3, $second->skipped_records, 'skips still show in the batch totals');
        $this->assertSame(0, DB::table('sync_logs')->where('sync_batch_id', $second->id)->where('action', 'SKIP')->count());
        $this->assertSame(3, Item::count());
    }

    public function test_changed_rows_are_still_logged_with_their_diff(): void
    {
        $this->sync();
        DB::table('accurate_item')->where('ITEMNO', 'AUT.0002')->update(['ITEMDESCRIPTION' => 'Nama Baru']);

        $batch = $this->sync();

        $log = DB::table('sync_logs')->where('sync_batch_id', $batch->id)->where('source_id', 'AUT.0002')->first();
        $this->assertNotNull($log);
        $this->assertSame('UPDATE', $log->action);
        $this->assertStringContainsString('Nama Baru', (string) $log->new_data);
    }

    public function test_a_long_running_batch_with_a_recent_heartbeat_is_not_reaped(): void
    {
        $alive = SyncBatch::create([
            'sync_code' => 'SYNC-ALIVE', 'source' => 'accurate', 'status' => 'RUNNING',
            'current_step' => 'npbg', 'started_at' => now()->subHours(2),
        ]);
        $alive->forceFill(['updated_at' => now()->subMinutes(2)])->saveQuietly();

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertStatus(202)->assertJsonPath('data.id', $alive->id);
        $this->assertSame('RUNNING', $alive->fresh()->status, 'a 2-hour-old sync that is still making progress must not be killed');
    }

    public function test_a_silent_batch_is_reaped_and_a_new_sync_can_start(): void
    {
        $dead = SyncBatch::create([
            'sync_code' => 'SYNC-DEAD', 'source' => 'accurate', 'status' => 'RUNNING',
            'current_step' => 'npbg', 'started_at' => now()->subHours(2),
        ]);
        $dead->forceFill(['updated_at' => now()->subMinutes(45)])->saveQuietly();

        $batch = $this->sync();

        $this->assertSame('FAILED', $dead->fresh()->status);
        $this->assertNotSame($dead->id, $batch->id);
    }

    public function test_agent_complete_is_idempotent_and_pollable(): void
    {
        $agent = User::factory()->create();
        Sanctum::actingAs($agent, ['agent:sync']);

        $id = $this->postJson('/api/agent/sync/sessions')->assertCreated()->json('data.id');

        // inline in tests: processing finishes inside the call
        $this->postJson("/api/agent/sync/sessions/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'SUCCESS');
        $this->getJson("/api/agent/sync/sessions/{$id}")->assertOk()->assertJsonPath('data.status', 'SUCCESS');

        // a retry of complete() never processes the batch a second time
        $this->postJson("/api/agent/sync/sessions/{$id}/complete")->assertStatus(409);
        $this->assertSame(1, SyncBatch::count());
    }

    public function test_status_poll_reports_a_dead_worker_as_failed(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['agent:sync']);
        $dead = SyncBatch::create([
            'sync_code' => 'SYNC-DEAD2', 'source' => 'accurate', 'status' => 'RUNNING',
            'current_step' => 'queued', 'started_at' => now()->subHour(),
        ]);
        $dead->forceFill(['updated_at' => now()->subMinutes(25)])->saveQuietly();

        $this->getJson("/api/agent/sync/sessions/{$dead->id}")->assertOk()->assertJsonPath('data.status', 'FAILED');
    }
}
