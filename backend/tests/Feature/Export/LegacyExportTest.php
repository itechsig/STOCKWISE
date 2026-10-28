<?php

namespace Tests\Feature\Export;

use App\Models\Category;
use App\Models\Item;
use App\Models\Site;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Replika Excel lama (docs/excel-data-mapping.md) — memastikan tiap file punya sheet, kolom,
 * dan formula yang sama seperti aslinya, terisi data live dari database.
 */
class LegacyExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public static function keyProvider(): array
    {
        return [
            ['data', 'admin_gudang'],
            ['ppb-ri', 'purchasing'],
            ['npbg', 'admin_gudang'],
            ['borrow-lend', 'admin_gudang'],
            ['stpp', 'admin_gudang'],
            ['ban-luar', 'admin_gudang'],
            ['maintenance-assets', 'admin_gudang'],
            ['manufaktur-assembly', 'admin_gudang'],
            ['pengembalian-bekas', 'admin_gudang'],
        ];
    }

    #[DataProvider('keyProvider')]
    public function test_every_legacy_workbook_downloads_as_xlsx(string $key, string $role): void
    {
        $this->actingAsRole($role, ['site_id' => $this->site->id]);

        $res = $this->get("/api/legacy-export/{$key}")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('content-type'));
    }

    public function test_unknown_key_is_404(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->getJson('/api/legacy-export/does-not-exist')->assertNotFound();
    }

    public function test_karyawan_lacks_the_module_permission(): void
    {
        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->getJson('/api/legacy-export/data')->assertForbidden();
        $this->getJson('/api/legacy-export/npbg')->assertForbidden();
    }

    public function test_index_lists_all_nine_workbooks(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $this->getJson('/api/legacy-export')->assertOk()->assertJsonCount(9, 'data');
    }

    /** DATA.xlsx — sheet, header, dan formula SS/MIN PR harus identik dengan file asli. */
    public function test_data_master_matches_original_layout_and_formulas(): void
    {
        $unit = Unit::factory()->create(['code' => 'PCS']);
        $category = Category::create(['name' => 'ASSET', 'level' => 2, 'path' => 'Assets > ASSET', 'is_active' => true]);
        $item = Item::factory()->create([
            'code' => 'AST.0001', 'description' => 'VAPORIZER ALUMUNIUM', 'category_id' => $category->id,
            'unit_id' => $unit->id, 'lead_time_days' => 5,
        ]);
        $item->safetyStocks()->create(['safety_stock' => 1, 'min_pr' => 2, 'sqrt_lt' => 0.4082, 'is_effective' => true]);

        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $bytes = $this->get('/api/legacy-export/data')->assertOk()->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'sw-legacy-').'.xlsx';
        file_put_contents($path, $bytes);

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $book = $reader->load($path);
        @unlink($path);

        $this->assertContains('DATABASE UTAMA', $book->getSheetNames());
        $this->assertContains('SAFETY STOCK ASSETS', $book->getSheetNames());

        $utama = $book->getSheetByName('DATABASE UTAMA');
        $this->assertSame('Kode Barang', $utama->getCell('A1')->getValue());
        $this->assertSame('SAFETY STOCK', $utama->getCell('R1')->getValue());
        $this->assertSame('AST.0001', $utama->getCell('A2')->getValue());
        $this->assertSame('Assets', $utama->getCell('B2')->getValue());
        $this->assertSame('ASSET', $utama->getCell('C2')->getValue());

        $ss = $book->getSheetByName('SAFETY STOCK ASSETS');
        $this->assertSame('ITEM DESCRIPTION', $ss->getCell('B3')->getValue());
        $this->assertSame('VAPORIZER ALUMUNIUM', $ss->getCell('B5')->getValue());
        $this->assertSame('=SUM(C5:N5)*1/12', $ss->getCell('O5')->getValue());
        $this->assertSame('=SUM(D5:N5)*3/12', $ss->getCell('P5')->getValue());
        $this->assertSame('=SQRT(S5/30)', $ss->getCell('T5')->getValue());
        $this->assertSame('=ROUNDUP((2.33*O5)*T5,0)', $ss->getCell('U5')->getValue());
        $this->assertSame('=ROUNDUP((O5*S5/30)+1,0)', $ss->getCell('V5')->getValue());
    }
}
