<?php

namespace Tests\Feature\Agent;

use App\Models\Item;
use App\Models\SyncBatch;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests the office Sync Agent's push path: POST /api/agent/sync/sessions ->
 * .../tables/{table} -> .../complete (or .../fail). Gated by the Sanctum
 * `agent:sync` ability, never the RBAC `permission:` middleware — see
 * tests/Feature/Accurate/AccurateSyncTest.php for the manual re-run
 * ("Sync Accurate" button) side, which shares the same downstream
 * mapping/upsert logic (AccurateSyncService::finishFromStaging()).
 */
class AccurateIngestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function agentColumns(): array
    {
        return [
            ['name' => 'ITEMNO', 'type' => 'VARCHAR', 'length' => 20, 'nullable' => false],
            ['name' => 'ITEMDESCRIPTION', 'type' => 'VARCHAR', 'length' => 255, 'nullable' => true],
            ['name' => 'UNIT1', 'type' => 'VARCHAR', 'length' => 20, 'nullable' => true],
            ['name' => 'SUSPENDED', 'type' => 'SMALLINT', 'length' => null, 'nullable' => true],
            ['name' => 'QUANTITY', 'type' => 'DOUBLE', 'length' => null, 'nullable' => true],
            ['name' => 'ONORDER', 'type' => 'DOUBLE', 'length' => null, 'nullable' => true],
            ['name' => 'PARENTITEM', 'type' => 'VARCHAR', 'length' => 20, 'nullable' => true],
        ];
    }

    public function test_ingest_routes_require_the_agent_sync_ability(): void
    {
        $this->postJson('/api/agent/sync/sessions')->assertUnauthorized();

        // A normal human Sanctum session (no agent:sync ability) must be refused too —
        // this is deliberately not the RBAC permission system.
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/agent/sync/sessions')->assertForbidden();
    }

    public function test_unwhitelisted_table_name_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'agent-test']), ['agent:sync']);

        $session = $this->postJson('/api/agent/sync/sessions')->assertCreated();
        $batchId = $session->json('data.id');

        $res = $this->postJson("/api/agent/sync/sessions/{$batchId}/tables/USERS", [
            'columns' => [['name' => 'ID', 'type' => 'INT', 'length' => null, 'nullable' => false]],
            'rows' => [],
            'is_first_chunk' => true,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('not a whitelisted', $res->json('message'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('accurate_users'));
    }

    public function test_full_session_pushes_item_table_and_completes_via_existing_business_logic(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        Sanctum::actingAs(User::factory()->create(['username' => 'agent-test']), ['agent:sync']);

        $session = $this->postJson('/api/agent/sync/sessions')->assertCreated();
        $session->assertJsonPath('data.is_new_session', true);
        $batchId = $session->json('data.id');

        $push = $this->postJson("/api/agent/sync/sessions/{$batchId}/tables/ITEM", [
            'columns' => $this->agentColumns(),
            'primary_key' => ['ITEMNO'],
            'rows' => [[
                'ITEMNO' => 'AGT.0001', 'ITEMDESCRIPTION' => 'Agent Pushed Item', 'UNIT1' => 'PCS',
                'SUSPENDED' => 0, 'QUANTITY' => 42, 'ONORDER' => 0, 'PARENTITEM' => null,
            ]],
            'is_first_chunk' => true,
        ]);
        $push->assertOk()->assertJsonPath('data.inserted', 1);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('accurate_item'));
        $this->assertSame('AGT.0001', DB::table('accurate_item')->value('ITEMNO'));

        $complete = $this->postJson("/api/agent/sync/sessions/{$batchId}/complete");
        $complete->assertOk()->assertJsonPath('data.status', 'SUCCESS');

        $item = Item::where('code', 'AGT.0001')->firstOrFail();
        $this->assertSame('Agent Pushed Item', $item->description);
        $this->assertSame('accurate', $item->source);
        $this->assertEquals(42, $item->accurate_qty_onhand);

        $batch = SyncBatch::findOrFail($batchId);
        $this->assertSame('SUCCESS', $batch->status);
        $this->assertSame(1, $batch->inserted_records);
    }

    public function test_second_chunk_of_the_same_table_appends_instead_of_recreating(): void
    {
        Unit::factory()->create(['code' => 'PCS']);
        Sanctum::actingAs(User::factory()->create(['username' => 'agent-test']), ['agent:sync']);

        $batchId = $this->postJson('/api/agent/sync/sessions')->json('data.id');

        $this->postJson("/api/agent/sync/sessions/{$batchId}/tables/ITEM", [
            'columns' => $this->agentColumns(), 'primary_key' => ['ITEMNO'],
            'rows' => [['ITEMNO' => 'CHK.0001', 'ITEMDESCRIPTION' => 'First chunk', 'UNIT1' => 'PCS', 'SUSPENDED' => 0, 'QUANTITY' => 1, 'ONORDER' => 0, 'PARENTITEM' => null]],
            'is_first_chunk' => true,
        ])->assertOk();

        $this->postJson("/api/agent/sync/sessions/{$batchId}/tables/ITEM", [
            'columns' => $this->agentColumns(),
            'rows' => [['ITEMNO' => 'CHK.0002', 'ITEMDESCRIPTION' => 'Second chunk', 'UNIT1' => 'PCS', 'SUSPENDED' => 0, 'QUANTITY' => 2, 'ONORDER' => 0, 'PARENTITEM' => null]],
            'is_first_chunk' => false,
        ])->assertOk();

        $this->assertSame(2, DB::table('accurate_item')->count());
    }

    public function test_fail_marks_the_batch_failed_with_the_agents_message(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'agent-test']), ['agent:sync']);

        $batchId = $this->postJson('/api/agent/sync/sessions')->json('data.id');

        $res = $this->postJson("/api/agent/sync/sessions/{$batchId}/fail", [
            'message' => 'Restore gagal: gbak exited with code 1 (backup file corrupt).',
        ]);

        $res->assertOk()->assertJsonPath('data.status', 'FAILED');

        $batch = SyncBatch::findOrFail($batchId);
        $this->assertSame('FAILED', $batch->status);
        $this->assertStringContainsString('gbak exited', $batch->error_message);
        $this->assertNotNull($batch->finished_at);
    }

    public function test_second_start_session_while_one_is_running_returns_the_same_batch(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'agent-test']), ['agent:sync']);

        $first = $this->postJson('/api/agent/sync/sessions')->assertCreated();
        $second = $this->postJson('/api/agent/sync/sessions')->assertCreated();

        $second->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.is_new_session', false);
    }

    /**
     * config('accurate.mirror_tables') must stay identical to the Python
     * Agent's own whitelist (sync-service/mapping/tables.py) — StagingTableWriter
     * only trusts the Laravel-side copy, so a drift here would silently make
     * the Agent unable to push a table Python thinks it's mirroring.
     */
    public function test_mirror_table_whitelist_matches_the_python_agent(): void
    {
        $path = base_path('../sync-service/mapping/tables.py');
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $pythonTables = [];
        foreach (['MIRROR_TABLES', 'OPTIONAL_MIRROR_TABLES', 'DERIVED_TABLES'] as $list) {
            preg_match('/^'.$list.'\s*=\s*\[(.*?)\]/ms', $source, $m);
            $this->assertNotEmpty($m, "Could not find {$list} list in tables.py");
            preg_match_all('/"([A-Z0-9_]+)"/', $m[1], $tables);
            $pythonTables = array_merge($pythonTables, $tables[1]);
        }

        $this->assertNotEmpty($pythonTables);
        $this->assertEqualsCanonicalizing($pythonTables, config('accurate.mirror_tables'));
    }
}
