<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\ItemSafetyStock;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafetyStockApiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_index_and_crud_require_permission(): void
    {
        $this->getJson('/api/safety-stocks')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->getJson('/api/safety-stocks')->assertForbidden();
        $this->postJson('/api/safety-stocks', [])->assertForbidden();
    }

    public function test_create_computes_formula_and_marks_first_row_effective(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang');

        $res = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id,
            'source_category' => 'ASSETS',
            'avg_usage_3m' => 10,
            'lead_time_days' => 30,
        ])->assertCreated();

        // lead_time_demand = 10*(30/30) = 10; safety_stock = ROUNDUP(2.33*10,0) = 24; min_pr = ROUNDUP(10+24,0) = 34
        // sqrt_lt = SQRT(30/30) = 1 (informational only, no longer part of the formula)
        $res->assertJsonPath('data.sqrt_lt', 1)
            ->assertJsonPath('data.safety_stock', 24)
            ->assertJsonPath('data.min_pr', 34)
            ->assertJsonPath('data.is_effective', true)
            ->assertJsonPath('data.needs_review', false)
            ->assertJsonPath('data.item_code', $item->code);
    }

    public function test_second_row_for_same_item_is_a_conflict_until_resolved(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang');

        $first = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 10, 'lead_time_days' => 30,
        ])->json('data');

        $second = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 20, 'lead_time_days' => 15,
        ])->assertCreated()
            ->assertJsonPath('data.is_effective', false)
            ->assertJsonPath('data.needs_review', true)
            ->json('data');

        $this->assertTrue(ItemSafetyStock::find($first['id'])->is_effective);
        $this->assertFalse(ItemSafetyStock::find($second['id'])->is_effective);

        $this->postJson("/api/safety-stocks/{$second['id']}/resolve-conflict")
            ->assertOk()
            ->assertJsonPath('data.is_effective', true)
            ->assertJsonPath('data.needs_review', false);

        $this->assertFalse(ItemSafetyStock::find($first['id'])->fresh()->is_effective);
        $this->assertTrue(ItemSafetyStock::find($second['id'])->fresh()->is_effective);
        $this->assertTrue(ItemSafetyStock::find($first['id'])->fresh()->needs_review);
    }

    public function test_update_recomputes_formula(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang');
        $row = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 10, 'lead_time_days' => 30,
        ])->json('data');

        // lead_time_days=0 -> lead_time_demand=0 -> safety_stock=0, min_pr=0 (no flat +1 anymore)
        $this->putJson("/api/safety-stocks/{$row['id']}", [
            'avg_usage_3m' => 5, 'lead_time_days' => 0,
        ])->assertOk()
            ->assertJsonPath('data.sqrt_lt', 0)
            ->assertJsonPath('data.safety_stock', 0)
            ->assertJsonPath('data.min_pr', 0);
    }

    public function test_delete_promotes_next_highest_row_to_effective(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang');
        $high = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 50, 'lead_time_days' => 30,
        ])->json('data');
        $low = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 5, 'lead_time_days' => 5,
        ])->json('data');

        $this->deleteJson("/api/safety-stocks/{$high['id']}")->assertOk();

        $this->assertTrue(ItemSafetyStock::find($low['id'])->is_effective);
    }

    public function test_resolve_conflict_requires_dedicated_permission(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang');
        $row = $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 10, 'lead_time_days' => 30,
        ])->json('data');

        $this->actingAsRole('anak_gudang');
        $this->postJson("/api/safety-stocks/{$row['id']}/resolve-conflict")->assertForbidden();
    }

    public function test_index_filters_by_search_and_needs_review(): void
    {
        $item = Item::factory()->create(['code' => 'SS.001', 'description' => 'Katup Regulator']);
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/safety-stocks', [
            'item_id' => $item->id, 'avg_usage_3m' => 10, 'lead_time_days' => 30,
        ])->assertCreated();

        $this->getJson('/api/safety-stocks?search=Regulator')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/safety-stocks?search=NOMATCH')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/safety-stocks?needs_review=1')->assertOk()->assertJsonCount(0, 'data');
    }
}
