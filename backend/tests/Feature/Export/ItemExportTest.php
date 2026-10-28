<?php

namespace Tests\Feature\Export;

use App\Models\Item;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Export Master Barang (Excel/PDF) — harus mengikuti filter yang sedang aktif, bukan seluruh data. */
class ItemExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_export_items_respects_active_filter(): void
    {
        Item::factory()->create(['code' => 'AUT.0001', 'accurate_category_anak_1' => 'AUTOMOTIVE WHEELS & TIRES']);
        Item::factory()->create(['code' => 'AST.0001', 'accurate_category_anak_1' => 'ASSETS']);

        $this->actingAsRole('admin_gudang');

        // Tanpa filter: keduanya ikut.
        $all = $this->get('/api/export/items?format=csv')->assertOk();
        $this->assertStringContainsString('AUT.0001', $all->streamedContent());
        $this->assertStringContainsString('AST.0001', $all->streamedContent());

        // Dengan filter kategori: hanya barang yang match yang ikut ter-export.
        $filtered = $this->get('/api/export/items?format=csv&'.http_build_query([
            'accurate_category_anak_1' => 'ASSETS',
        ]))->assertOk();
        $body = $filtered->streamedContent();
        $this->assertStringContainsString('AST.0001', $body);
        $this->assertStringNotContainsString('AUT.0001', $body);
    }

    public function test_export_items_xlsx_and_pdf_formats(): void
    {
        Item::factory()->create(['code' => 'AUT.0001']);
        $this->actingAsRole('admin_gudang');

        $xlsx = $this->get('/api/export/items?format=xlsx');
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));

        $pdf = $this->get('/api/export/items?format=pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('text/html', $pdf->headers->get('content-type'));
    }

    public function test_karyawan_cannot_export_items(): void
    {
        $this->actingAsRole('karyawan');
        $this->getJson('/api/export/items?format=csv')->assertForbidden();
    }
}
