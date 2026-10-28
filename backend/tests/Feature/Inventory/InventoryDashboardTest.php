<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAnalyzer;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uses the REAL pipeline (Item + ItemSafetyStock + Inventory ->
 * InventoryAnalyzer::run() -> inventory_snapshots), same as
 * InventoryAnalysisTest, so these tests exercise the same
 * StockwiseEngine formulas the dashboard reads — not hand-built fixture
 * numbers that could silently drift from the real calculation.
 */
class InventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_every_logged_in_role_sees_the_dashboard(): void
    {
        $this->getJson('/api/dashboard/inventory')->assertUnauthorized();

        foreach (['karyawan', 'lapangan_gudang', 'anak_gudang'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/dashboard/inventory')->assertOk();
            $this->getJson('/api/dashboard/inventory/filters')->assertOk()
                ->assertJsonStructure(['data' => ['categories', 'units', 'warehouses']]);
        }
    }

    public function test_empty_dataset_returns_zeroed_kpis_and_a_clear_note_not_a_crash(): void
    {
        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.kpis.total_barang', 0)
            ->assertJsonPath('data.kpis.barang_aman', 0)
            ->assertJsonPath('data.kpis.perlu_dibeli', 0)
            ->assertJsonPath('data.kpis.total_stok', 0)
            ->assertJsonPath('data.health_score.value', 0)
            ->assertJsonPath('data.health_score.category', 'Kritis')
            ->assertJsonPath('data.charts.top_deficit', [])
            ->assertJsonPath('data.charts.ppb_status', [])
            ->assertJsonPath('data.charts.npbg_per_month', [])
            ->assertJsonPath('data.priority_items', [])
            ->assertJsonPath('data.notes.0', 'Tidak ada barang yang cocok dengan filter saat ini.');
    }

    public function test_status_kpis_and_health_score_across_aman_tidak_aman_bep(): void
    {
        $wh = Warehouse::factory()->create();
        // AMAN: sisa 100 >= ss 50
        Item::factory()->safetyStock(50)->withStock(100, 0, $wh->id)->create(['lead_time_days' => 7]);
        // TIDAK_AMAN: sisa 40 < ss 50, deficit 10
        Item::factory()->safetyStock(50)->withStock(40, 0, $wh->id)->create(['lead_time_days' => 7]);
        // BEP: sisa 0, ss 0
        Item::factory()->safetyStock(0)->withStock(0, 0, $wh->id)->create(['lead_time_days' => 5]);
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.kpis.total_barang', 3)
            ->assertJsonPath('data.kpis.barang_aman', 1)
            ->assertJsonPath('data.kpis.perlu_dibeli', 1)
            ->assertJsonPath('data.kpis.barang_bep', 1)
            ->assertJsonPath('data.kpis.stok_habis', 1) // only the BEP item has sisa_stok == 0
            ->assertJsonPath('data.kpis.total_kekurangan', 10);

        // Health Score: (AMAN 1 + BEP 1) / 3 = 66.7% -> Perlu Perhatian
        $res->assertJsonPath('data.health_score.value', 66.7)
            ->assertJsonPath('data.health_score.category', 'Perlu Perhatian');

        $statuses = collect($res->json('data.charts.status_distribution'))->pluck('value', 'name');
        $this->assertSame(1, $statuses['AMAN']);
        $this->assertSame(1, $statuses['TIDAK_AMAN']);
        $this->assertSame(1, $statuses['BEP']);
    }

    public function test_sisa_stok_zero_with_positive_safety_stock_is_tidak_aman_not_bep(): void
    {
        $wh = Warehouse::factory()->create();
        Item::factory()->safetyStock(20)->withStock(0, 0, $wh->id)->create();
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.kpis.barang_bep', 0)
            ->assertJsonPath('data.kpis.perlu_dibeli', 1)
            // zero stock but TIDAK_AMAN -> "Perlu Dibeli", not "Stok Habis" (= BEP only)
            ->assertJsonPath('data.kpis.stok_habis', 0);
    }

    public function test_safety_stock_zero_with_positive_sisa_stok_is_aman(): void
    {
        $wh = Warehouse::factory()->create();
        Item::factory()->safetyStock(0)->withStock(15, 0, $wh->id)->create();
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.kpis.barang_aman', 1)
            ->assertJsonPath('data.kpis.barang_bep', 0)
            ->assertJsonPath('data.kpis.stok_habis', 0);
    }

    public function test_deficit_zero_item_produces_no_deficit_and_no_priority_entry(): void
    {
        $wh = Warehouse::factory()->create();
        // Sisa Stok exactly equals Safety Stock -> Selisih 0 -> AMAN, deficit 0
        Item::factory()->safetyStock(30)->withStock(30, 0, $wh->id)->create();
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.kpis.total_kekurangan', 0)
            ->assertJsonPath('data.charts.top_deficit', [])
            ->assertJsonPath('data.priority_items', []);
    }

    public function test_lead_time_null_is_treated_as_zero_not_guessed(): void
    {
        $wh = Warehouse::factory()->create();
        Item::factory()->safetyStock(50)->withStock(10, 0, $wh->id)->create(['lead_time_days' => null]);
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        // priority_score = deficit(40)*2 + lead_time(0)*1 = 80
        $res->assertJsonPath('data.priority_items.0.lead_time_days', 0)
            ->assertJsonPath('data.priority_items.0.priority_score', 80);
    }

    public function test_filter_reduces_kpis_and_health_score_reacts(): void
    {
        $wh1 = Warehouse::factory()->create(['name' => 'Gudang Utama']);
        $wh2 = Warehouse::factory()->create(['name' => 'Gudang Cabang']);
        Item::factory()->safetyStock(10)->withStock(100, 0, $wh1->id)->create(['default_warehouse_id' => $wh1->id]);
        Item::factory()->safetyStock(10)->withStock(0, 0, $wh2->id)->create(['default_warehouse_id' => $wh2->id]);
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');

        $unfiltered = $this->getJson('/api/dashboard/inventory')->assertOk();
        $unfiltered->assertJsonPath('data.kpis.total_barang', 2);

        $filtered = $this->getJson('/api/dashboard/inventory?warehouse_id='.$wh1->id)->assertOk();
        $filtered->assertJsonPath('data.kpis.total_barang', 1)
            ->assertJsonPath('data.kpis.barang_aman', 1)
            ->assertJsonPath('data.health_score.value', 100)
            ->assertJsonPath('data.health_score.category', 'Sehat');
    }

    public function test_ppb_and_npbg_charts_are_empty_arrays_when_no_matching_rows_exist(): void
    {
        $wh = Warehouse::factory()->create();
        Item::factory()->safetyStock(10)->withStock(5, 0, $wh->id)->create();
        app(InventoryAnalyzer::class)->run();
        // No `ppb`/`npbg` rows seeded at all for this item's code.

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.charts.ppb_status', [])
            ->assertJsonPath('data.charts.ppb_per_divisi', [])
            ->assertJsonPath('data.charts.npbg_per_month', [])
            ->assertJsonPath('data.charts.npbg_top_usage', [])
            ->assertJsonPath('data.charts.npbg_top_divisi', []);
    }

    public function test_ppb_and_npbg_charts_reflect_real_rows_matched_by_kode_barang(): void
    {
        $wh = Warehouse::factory()->create();
        $item = Item::factory()->safetyStock(10)->withStock(5, 0, $wh->id)->create();
        app(InventoryAnalyzer::class)->run();

        DB::table('ppb')->insert([
            'accurate_reqid' => 1, 'accurate_seq' => 1, 'no_ppb' => 'PPB/ATK/26/I/001',
            'status' => 'OPEN', 'line_status' => 'REQUESTED', 'divisi' => 'ATK', 'kode_barang' => $item->code,
            'kuantitas' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('npbg')->insert([
            'accurate_arinvoice_id' => 1, 'accurate_seq' => 1, 'no_npbg' => 'NA/26/I/001',
            'tgl_npbg' => now()->toDateString(), 'divisi' => 'GUDANG',
            'kode_barang' => $item->code, 'deskripsi_barang' => $item->description,
            'kuantitas' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson('/api/dashboard/inventory')->assertOk();

        $res->assertJsonPath('data.charts.ppb_status.0.name', 'REQUESTED')
            ->assertJsonPath('data.charts.ppb_status.0.value', 1)
            ->assertJsonPath('data.charts.npbg_top_divisi.0.name', 'GUDANG');
    }

    public function test_invalid_filters_return_clear_validation_errors_not_a_silent_empty_result(): void
    {
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/dashboard/inventory?status=NOT_A_REAL_STATUS')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/dashboard/inventory?warehouse_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('warehouse_id');

        $this->getJson('/api/dashboard/inventory?lead_time_min=10&lead_time_max=5')
            ->assertStatus(422)
            ->assertJsonValidationErrors('lead_time_max');

        // Indonesian, human-readable field name + reason — not Laravel's raw
        // "The lead time max field must be greater than or equal to lead
        // time min." (see lang/id/validation.php + bootstrap/app.php).
        $res = $this->getJson('/api/dashboard/inventory?lead_time_min=10&lead_time_max=5');
        $this->assertStringContainsString('lead time maksimum', $res->json('errors.lead_time_max.0'));
        $this->assertSame('Data yang dikirim tidak valid. Periksa kembali isian yang ditandai.', $res->json('message'));
    }

    public function test_high_lead_time_threshold_param_changes_the_note_without_changing_kpis(): void
    {
        $wh = Warehouse::factory()->create();
        Item::factory()->safetyStock(50)->withStock(10, 0, $wh->id)->create(['lead_time_days' => 20]);
        app(InventoryAnalyzer::class)->run();

        $this->actingAsRole('admin_gudang');

        $lowThreshold = $this->getJson('/api/dashboard/inventory?high_lead_time_threshold=5')->assertOk();
        $this->assertStringContainsString('Lead Time tinggi', collect($lowThreshold->json('data.notes'))->implode(' | '));

        $highThreshold = $this->getJson('/api/dashboard/inventory?high_lead_time_threshold=999')->assertOk();
        $this->assertStringNotContainsString('Lead Time tinggi', collect($highThreshold->json('data.notes'))->implode(' | '));

        // KPIs must be identical regardless — the threshold only affects the note.
        $this->assertSame($lowThreshold->json('data.kpis'), $highThreshold->json('data.kpis'));
    }
}
