<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_index_requires_permission(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->getJson('/api/items')->assertForbidden();
    }

    public function test_karyawan_can_use_item_lookup_but_not_full_index(): void
    {
        Item::factory()->create(['code' => 'BAN.777', 'description' => 'BAN LUAR STEEL']);
        $this->actingAsRole('karyawan');

        $this->getJson('/api/items')->assertForbidden();
        $this->getJson('/api/items/lookup?search=BAN')->assertOk()
            ->assertJsonPath('data.0.code', 'BAN.777');
        $this->getJson('/api/items/lookup?search=b')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_lists_paginated_items(): void
    {
        Item::factory()->count(30)->create();
        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/items?per_page=10')->assertOk();
        $res->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonStructure(['data' => [['id', 'code', 'description', 'category', 'unit']], 'meta' => ['page', 'per_page', 'total', 'last_page']]);
    }

    /**
     * Real bug: the response used to be Laravel's default paginator shape
     * (meta.current_page), while the frontend's shared Pagination component
     * reads meta.page — the "next" button silently computed NaN and users
     * could never get past the first 25 items.
     */
    public function test_index_pagination_meta_matches_shared_frontend_contract(): void
    {
        Item::factory()->count(30)->create();
        $this->actingAsRole('admin_gudang');

        $page1 = $this->getJson('/api/items?per_page=10&page=1')->assertOk();
        $page1->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 30);

        $page2 = $this->getJson('/api/items?per_page=10&page=2')->assertOk();
        $page2->assertJsonPath('meta.page', 2);

        // different rows on different pages — proves paging actually moves, not just the label
        $this->assertNotEquals($page1->json('data.0.id'), $page2->json('data.0.id'));
    }

    public function test_search_and_filter(): void
    {
        $cat = Category::factory()->create(['name' => 'Automotive', 'path' => 'Automotive']);
        Item::factory()->create(['code' => 'BAN.001', 'description' => 'BAN LUAR STEEL 750', 'category_id' => $cat->id]);
        Item::factory()->create(['code' => 'ATK.001', 'description' => 'PULPEN HITAM']);

        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/items?search=BAN')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BAN.001');
        $this->getJson('/api/items?category_induk=Automotive')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/items?code=ATK')->assertOk()->assertJsonPath('data.0.code', 'ATK.001');
    }

    public function test_filter_by_accurate_category_and_unit(): void
    {
        $pcs = Unit::factory()->create(['code' => 'PCS']);
        $box = Unit::factory()->create(['code' => 'BOX']);
        Item::factory()->create(['code' => 'AUT.0001', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES', 'unit_id' => $pcs->id]);
        Item::factory()->create(['code' => 'AST.0001', 'accurate_category_anak_1' => 'ASSETS', 'unit_id' => $box->id]);

        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/items?accurate_category_anak_1='.urlencode('AUTOMOTIVE WHEELS & TIRES'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AUT.0001');
        $this->getJson("/api/items?unit_id={$box->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AST.0001');
    }

    public function test_accurate_category_options_lists_distinct_branches_only(): void
    {
        Item::factory()->create([
            'code' => 'AUT.0001', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES',
            'accurate_category_anak_2' => 'BAN LUAR (TIRES)', 'accurate_category_anak_3' => 'BAN LUAR BENANG (NYLON TIRES)',
        ]);
        Item::factory()->create([
            // Duplicate branch — must appear only once in the result.
            'code' => 'AUT.0002', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES',
            'accurate_category_anak_2' => 'BAN LUAR (TIRES)', 'accurate_category_anak_3' => 'BAN LUAR BENANG (NYLON TIRES)',
        ]);
        Item::factory()->create(['code' => 'AST.0001', 'accurate_category_anak_1' => 'ASSETS']);
        Item::factory()->create(['code' => 'NOC.0001', 'accurate_category_anak_1' => null]);

        $this->actingAsRole('admin_gudang');

        $res = $this->getJson('/api/items/accurate-categories')->assertOk();
        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.accurate_category_anak_1', 'ASSETS')
            ->assertJsonPath('data.0.accurate_category_anak_2', null)
            ->assertJsonPath('data.1.accurate_category_anak_1', 'AUTOMOTIVE WHEELS & TIRES')
            ->assertJsonPath('data.1.accurate_category_anak_2', 'BAN LUAR (TIRES)')
            ->assertJsonPath('data.1.accurate_category_anak_3', 'BAN LUAR BENANG (NYLON TIRES)');
    }

    public function test_filter_by_accurate_category_anak_2_and_anak_3(): void
    {
        Item::factory()->create([
            'code' => 'AUT.0001', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES',
            'accurate_category_anak_2' => 'BAN LUAR (TIRES)', 'accurate_category_anak_3' => 'BAN LUAR BENANG (NYLON TIRES)',
        ]);
        Item::factory()->create([
            'code' => 'AUT.0080', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES',
            'accurate_category_anak_2' => 'BAN DALAM (TUBES)', 'accurate_category_anak_3' => null,
        ]);

        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/items?'.http_build_query(['accurate_category_anak_2' => 'BAN DALAM (TUBES)']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AUT.0080');
        $this->getJson('/api/items?'.http_build_query(['accurate_category_anak_3' => 'BAN LUAR BENANG (NYLON TIRES)']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'AUT.0001');
    }

    public function test_show_returns_detail_with_relations(): void
    {
        $item = Item::factory()->safetyStock(10)->withStock(25)->create();
        $this->actingAsRole('admin_gudang');

        $this->getJson("/api/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.code', $item->code)
            ->assertJsonPath('data.safety_stock', 10)
            ->assertJsonCount(1, 'data.inventory');
    }

    public function test_create_update_delete_flow_and_authorization(): void
    {
        $unit = Unit::factory()->create(['code' => 'PCS']); // the UOM must be one of ItemOptions::UOM
        $payload = ['code' => 'NEW.001', 'description' => 'BARANG BARU', 'unit_id' => $unit->id, 'lead_time_days' => 7];

        // karyawan cannot create
        $this->actingAsRole('karyawan');
        $this->postJson('/api/items', $payload)->assertForbidden();

        // admin_gudang can
        $this->actingAsRole('admin_gudang');
        $create = $this->postJson('/api/items', $payload)->assertCreated();
        $id = $create->json('data.id');
        $this->assertDatabaseHas('items', ['code' => 'NEW.001']);
        // kolom dengan default di DB (item_type, is_active) harus ikut kebawa di response create.
        $create->assertJsonPath('data.item_type', 'CONSUMABLE')->assertJsonPath('data.is_active', true);

        $this->putJson("/api/items/{$id}", ['description' => 'BARANG DIUBAH'])
            ->assertOk()->assertJsonPath('data.description', 'BARANG DIUBAH');

        $this->deleteJson("/api/items/{$id}")->assertOk();
        $this->assertSoftDeleted('items', ['id' => $id]);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        Item::factory()->create(['code' => 'DUP.001']);
        $this->actingAsRole('admin_gudang');

        $this->postJson('/api/items', ['code' => 'DUP.001', 'description' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_purchasing_can_view_but_not_edit_items(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('purchasing');

        $this->getJson("/api/items/{$item->id}")->assertOk();
        $this->putJson("/api/items/{$item->id}", ['description' => 'X'])->assertForbidden();
    }

    /** Master Barang harus lengkap sesuai kolom DATA.xlsx DATABASE UTAMA. */
    public function test_show_exposes_full_data_master_column_set(): void
    {
        $category = Category::create(['name' => 'ASSET', 'level' => 2, 'path' => 'Assets > ASSET', 'is_active' => true]);
        $warehouse = Warehouse::factory()->create(['code' => 'GUDANG 1']);
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'B.7.1', 'is_active' => true]);
        $item = Item::factory()->create([
            'category_id' => $category->id, 'needs_blueprint' => true,
            'default_warehouse_id' => $warehouse->id, 'default_location_id' => $location->id,
            'blueprint_3d_ref' => 'A.17.31',
        ]);

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/items/{$item->id}")->assertOk();

        // category_breakdown sekarang bersumber dari accurate_category_anak_*
        // (diisi oleh AccurateSyncService dari ITEMDESCRIPTION rantai
        // PARENTITEM), bukan dari tree kategori Excel (`categories`) lagi —
        // item ini dibuat via factory tanpa data Accurate sama sekali, jadi
        // breakdown-nya kosong semua meski `category_id` (Excel) tetap terisi.
        $res->assertJsonPath('data.category_breakdown.induk', null)
            ->assertJsonPath('data.category_breakdown.anak_1', null)
            ->assertJsonPath('data.category_breakdown.anak_2', null)
            ->assertJsonPath('data.needs_blueprint', true)
            ->assertJsonPath('data.default_warehouse.code', 'GUDANG 1')
            ->assertJsonPath('data.default_location.code', 'B.7.1')
            ->assertJsonPath('data.blueprint_3d_ref', 'A.17.31')
            ->assertJsonPath('data.alias_name', 'Tidak')
            ->assertJsonPath('data.aliases', []);
    }

    public function test_update_accepts_blueprint_and_location_fields(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A.1.1', 'is_active' => true]);
        $this->actingAsRole('admin_gudang');

        $this->putJson("/api/items/{$item->id}", [
            'default_warehouse_id' => $warehouse->id,
            'default_location_id' => $location->id,
            'blueprint_3d_ref' => 'C.1.2',
            'blueprint_img_path' => 'blueprints/x.png',
        ])->assertOk()
            ->assertJsonPath('data.default_warehouse.id', $warehouse->id)
            ->assertJsonPath('data.blueprint_3d_ref', 'C.1.2');

        $this->assertDatabaseHas('items', ['id' => $item->id, 'blueprint_img_path' => 'blueprints/x.png']);
    }

    /** Used by "custom" stock-opname scheduling to bulk-add every item matching a filter. */
    public function test_ids_endpoint_returns_all_matching_ids_unpaginated(): void
    {
        $cat = Category::factory()->create(['name' => 'Automotive', 'path' => 'Automotive']);
        $matching = Item::factory()->count(5)->create(['category_id' => $cat->id, 'is_active' => true]);
        Item::factory()->create(['category_id' => $cat->id, 'is_active' => false]); // inactive, must be excluded
        Item::factory()->create(); // different category, must be excluded

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/items/ids?category_id={$cat->id}")->assertOk();

        $res->assertJsonCount(5, 'data');
        $this->assertEqualsCanonicalizing($matching->pluck('id')->all(), $res->json('data'));
    }

    public function test_ids_endpoint_requires_permission(): void
    {
        $this->getJson('/api/items/ids')->assertUnauthorized();
        $this->actingAsRole('karyawan');
        $this->getJson('/api/items/ids')->assertForbidden();
    }
}
