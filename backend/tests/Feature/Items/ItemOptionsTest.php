<?php

namespace Tests\Feature\Items;

use App\Models\Item;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Produk: Kategori Induk / Anak 1 / 2 / 3 and UOM come from their fixed lists. */
class ItemOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_the_form_gets_the_lists_and_a_saved_item_must_use_them(): void
    {
        $pcs = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        $other = Unit::create(['code' => 'BTG', 'name' => 'Batang']);
        $this->actingAsRole('super_admin');

        $opt = $this->getJson('/api/items/options')->assertOk()->json('data');
        $this->assertSame(['Office Needs', 'Assets'], [$opt['induk'][0], $opt['induk'][11]]);
        $this->assertCount(12, $opt['induk']);
        $this->assertContains('REDUCING NIPPLE (M x M)', $opt['anak_1']);
        $this->assertContains('PIPE CARBON STEEL 1 1/2" SCH 40', $opt['anak_2']);
        $this->assertContains('MALE RUN TEE ADAPTER STAINLESS', $opt['anak_3']);
        $this->assertSame(['PCS'], array_column($opt['uom'], 'code'), 'only the UOM of the list (that exist)');

        $base = ['code' => 'OFN.0001', 'description' => 'PULPEN', 'unit_id' => $pcs->id];
        $this->postJson('/api/items', $base + ['accurate_category_induk' => 'Kantor'])->assertStatus(422)->assertJsonValidationErrors('accurate_category_induk');
        $this->postJson('/api/items', ['unit_id' => $other->id] + $base)->assertStatus(422)->assertJsonValidationErrors('unit_id');
        $made = $this->postJson('/api/items', $base + ['accurate_category_induk' => 'Office Needs', 'accurate_category_anak_1' => 'OFFICE STATIONERY',
            'accurate_category_anak_2' => 'MISCELLANEOUS', 'accurate_category_anak_3' => null])->assertCreated()->json('data');
        $this->assertSame(['Office Needs', 'OFFICE STATIONERY', 'MISCELLANEOUS', null], array_values($made['category_breakdown']));

        // an Accurate item keeps a value outside the lists when it is saved again
        $acc = Item::factory()->create(['code' => 'OLD.0001', 'accurate_category_induk' => 'Lama', 'unit_id' => $other->id]);
        $this->putJson("/api/items/{$acc->id}", ['accurate_category_induk' => 'Lama', 'unit_id' => $other->id, 'description' => 'X'])->assertOk();
        $this->putJson("/api/items/{$acc->id}", ['accurate_category_induk' => 'Baru'])->assertStatus(422);
    }
}
