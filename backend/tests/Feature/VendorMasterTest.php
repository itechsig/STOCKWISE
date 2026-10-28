<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Vendor & Supplier: one list for both, searchable, added / edited here. */
class VendorMasterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_list_search_add_and_edit(): void
    {
        Vendor::factory()->create(['name' => 'PT. GLOBAL HOSINDO', 'phone' => '031-777', 'source' => 'accurate']);
        $this->actingAsRole('super_admin');
        $made = $this->postJson('/api/vendors', ['name' => 'CV. SUPPLIER BAUT', 'address' => 'Jl. Raya Sidoarjo 1'])->assertCreated()->json('data');
        $this->postJson('/api/vendors', ['name' => 'CV. SUPPLIER BAUT'])->assertStatus(422); // one name once

        $this->getJson('/api/vendors?limit=2000')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/vendors?search=sidoarjo')->assertJsonCount(1, 'data')->assertJsonPath('data.0.address', 'Jl. Raya Sidoarjo 1');
        $this->getJson('/api/vendors?search=031')->assertJsonPath('data.0.source', 'accurate');
        $acc = Vendor::where('source', 'accurate')->sole();
        $this->putJson("/api/vendors/{$acc->id}", ['name' => 'GLOBAL BARU'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson("/api/vendors/{$acc->id}", ['name' => 'PT. GLOBAL HOSINDO', 'phone' => '031-888'])->assertOk();

        $this->putJson("/api/vendors/{$made['id']}", ['is_active' => false])->assertOk();
        $this->getJson('/api/vendors?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'CV. SUPPLIER BAUT');
    }
}
