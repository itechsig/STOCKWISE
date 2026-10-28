<?php

namespace Tests\Feature\Dashboard;

use App\Models\Site;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PHASE 9 + 10 — dashboard ringkasan & export laporan. */
class DashboardExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_dashboard_returns_role_aware_cards(): void
    {
        $site = Site::factory()->create();
        $this->actingAsRole('admin_gudang', ['site_id' => $site->id]);

        $res = $this->getJson('/api/dashboard')->assertOk();
        $res->assertJsonStructure(['data' => ['role', 'cards', 'charts', 'lists']]);
        $this->assertNotEmpty($res->json('data.cards'));
    }

    public function test_karyawan_dashboard_has_no_purchasing_cards(): void
    {
        $site = Site::factory()->create();
        $this->actingAsRole('karyawan', ['site_id' => $site->id]);

        $keys = collect($this->getJson('/api/dashboard')->assertOk()->json('data.cards'))->pluck('key');
        $this->assertFalse($keys->contains('po_open'));
    }

    public function test_export_inventory_csv_and_xlsx(): void
    {
        $site = Site::factory()->create();
        $this->actingAsRole('admin_gudang', ['site_id' => $site->id]);

        $csv = $this->get('/api/export/inventory?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));

        $xlsx = $this->get('/api/export/inventory?format=xlsx');
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
    }

    public function test_export_rejects_bad_format_and_unknown_dataset(): void
    {
        $site = Site::factory()->create();
        $this->actingAsRole('admin_gudang', ['site_id' => $site->id]);

        $this->getJson('/api/export/inventory?format=docx')->assertStatus(422);
        $this->getJson('/api/export/nonsense?format=csv')->assertNotFound();
    }

    public function test_karyawan_cannot_export_inventory(): void
    {
        $site = Site::factory()->create();
        $this->actingAsRole('karyawan', ['site_id' => $site->id]);
        $this->getJson('/api/export/inventory?format=csv')->assertForbidden();
    }
}
