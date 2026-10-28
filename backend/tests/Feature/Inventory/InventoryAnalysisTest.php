<?php

namespace Tests\Feature\Inventory;

use App\Models\InventorySnapshot;
use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAnalyzer;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function seedCatalog(): void
    {
        $wh = Warehouse::factory()->create();

        // TC-INV-001 style — AMAN
        Item::factory()->safetyStock(50)->withStock(100, 0, $wh->id)->create(['lead_time_days' => 7]);
        // TIDAK_AMAN, deficit 10
        Item::factory()->safetyStock(50)->withStock(40, 0, $wh->id)->create(['lead_time_days' => 7]);
        // BEP
        Item::factory()->safetyStock(0)->withStock(0, 0, $wh->id)->create(['lead_time_days' => 5]);
    }

    public function test_analyzer_writes_snapshots(): void
    {
        $this->seedCatalog();

        $run = app(InventoryAnalyzer::class)->run();

        $this->assertSame(3, $run->item_count);
        $this->assertSame(1, $run->tidak_aman_count);
        $this->assertDatabaseCount('inventory_snapshots', 3);

        $statuses = InventorySnapshot::pluck('status')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['AMAN', 'BEP', 'TIDAK_AMAN'], $statuses);
    }

    public function test_analysis_endpoint_returns_ranked_list(): void
    {
        $this->seedCatalog();
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/inventory/analysis?status=TIDAK_AMAN')->assertOk();
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'TIDAK_AMAN')
            ->assertJsonPath('data.0.deficit', 10)
            ->assertJsonStructure(['run' => ['lead_time_threshold', 'median_deficit']]);
    }

    public function test_analysis_endpoint_forbidden_without_permission(): void
    {
        $this->actingAsRole('karyawan');
        $this->getJson('/api/inventory/analysis')->assertForbidden();
    }

    public function test_projected_stock_warns_below_safety(): void
    {
        // Actual 7, Reserved 0, Safety 5, Request 5 -> projected 2 < 5 -> warning (TC-INV-004)
        $wh = Warehouse::factory()->create();
        $item = Item::factory()->safetyStock(5)->withStock(7, 0, $wh->id)->create();

        $this->actingAsRole('admin_gudang');

        $res = $this->postJson('/api/inventory/projected', [
            'lines' => [['item_id' => $item->id, 'warehouse_id' => $wh->id, 'qty' => 5]],
        ])->assertOk();

        $res->assertJsonPath('data.0.projected_stock', 2)
            ->assertJsonPath('data.0.below_safety', true)
            ->assertJsonPath('data.0.warning', 'Request ini akan menyebabkan stok berada di bawah Safety Stock.');
    }

    /** The list itself is always fresh (InventoryAnalyzer::compute(), no cache) — no "Hitung ulang" endpoint anymore. */
    public function test_analysis_endpoint_is_live_without_a_prior_run(): void
    {
        $this->seedCatalog();
        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/inventory/analysis?status=TIDAK_AMAN')->assertOk();
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.deficit', 10);
    }
}
