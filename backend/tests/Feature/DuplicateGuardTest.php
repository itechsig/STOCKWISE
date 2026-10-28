<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin A and admin B entering the same goods: a manual NPBG / PPB / PO / RI is not made twice. */
class DuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function test_a_second_npbg_for_the_same_item_and_customer_needs_a_confirmation(): void
    {
        $this->actingAsRole('super_admin', ['name' => 'ADMIN A']);
        $npbg = ['pelanggan' => 'HRD', 'tipe_npbg' => 'Non Penjualan', 'kode_barang' => 'ATK.0001', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 2, 'satuan' => 'RIM'];
        $first = $this->postJson('/api/npbg', $npbg)->assertCreated()->json('data.no_npbg');

        $this->actingAsRole('super_admin', ['name' => 'ADMIN B']);
        $msg = $this->postJson('/api/npbg', ['pelanggan' => ' hrd '] + $npbg)->assertStatus(422)->assertJsonValidationErrors('duplicate')->json('errors.duplicate.0');
        $this->assertStringContainsString($first, $msg);
        $this->assertStringContainsString('ADMIN A', $msg, 'says who made it');
        // another customer is another NPBG; a confirmed one is saved
        $this->postJson('/api/npbg', ['pelanggan' => 'GUDANG'] + $npbg)->assertCreated();
        $this->postJson('/api/npbg', $npbg + ['allow_duplicate' => true])->assertCreated();
    }

    public function test_ppb_the_same_line_of_one_ppb_never_twice(): void
    {
        $this->actingAsRole('super_admin');
        $ppb = ['no_ppb' => 'PPB/NA/26/X/001', 'tgl_ppb' => '2026-10-26', 'kode_barang' => 'SPR.0001', 'deskripsi_barang' => 'BAN', 'kuantitas' => 4, 'peminta' => 'Budi'];
        $this->postJson('/api/ppb', $ppb)->assertCreated();
        // the same PPB number and item: never, even confirmed
        $this->postJson('/api/ppb', $ppb + ['allow_duplicate' => true])->assertStatus(422)->assertJsonValidationErrors('duplicate');
        // another PPB number, the same item for the same requester lately: confirm first
        $this->postJson('/api/ppb', ['no_ppb' => 'PPB/NA/26/X/002'] + $ppb)->assertStatus(422)->assertJsonValidationErrors('duplicate');
        $this->postJson('/api/ppb', ['no_ppb' => 'PPB/NA/26/X/002', 'allow_duplicate' => true] + $ppb)->assertCreated();
    }

    public function test_po_the_same_vendor_and_items_need_a_confirmation(): void
    {
        $this->actingAsRole('super_admin');
        $po = ['vendor_id' => Vendor::factory()->create()->id, 'site_id' => Site::factory()->create()->id, 'date' => '2026-10-26',
            'items' => [['description_raw' => 'Kabel NYM 3x2.5', 'qty' => 2, 'unit_price' => 1000]]];
        $first = $this->postJson('/api/purchase-orders', $po)->assertSuccessful()->json('data.number');
        $msg = $this->postJson('/api/purchase-orders', $po)->assertStatus(422)->json('errors.duplicate.0');
        $this->assertStringContainsString($first, $msg);
        $this->postJson('/api/purchase-orders', $po + ['allow_duplicate' => true])->assertSuccessful();
    }

    public function test_ri_the_same_delivery_note_never_twice(): void
    {
        $this->actingAsRole('super_admin');
        $ri = ['divisi' => 'NV', 'deskripsi_barang' => 'Kabel', 'kuantitas' => 5, 'no_surat_jalan' => 'SJ-77', 'photos' => [self::PHOTO]];
        $no = $this->postJson('/api/ri', $ri)->assertCreated()->json('data.no_ri');
        $msg = $this->postJson('/api/ri', $ri + ['allow_duplicate' => true])->assertStatus(422)->json('errors.duplicate.0');
        $this->assertStringContainsString($no, $msg);
        // no delivery note: the same item, qty and vendor the same day needs a confirmation
        $free = ['divisi' => 'NV', 'deskripsi_barang' => 'Sample', 'kuantitas' => 1, 'photos' => [self::PHOTO]];
        $this->postJson('/api/ri', $free)->assertCreated();
        $this->postJson('/api/ri', $free)->assertStatus(422)->assertJsonValidationErrors('duplicate');
        $this->postJson('/api/ri', $free + ['allow_duplicate' => true])->assertCreated();
    }
}
