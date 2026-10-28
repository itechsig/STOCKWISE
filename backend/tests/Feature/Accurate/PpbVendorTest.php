<?php

namespace Tests\Feature\Accurate;

use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** PPB "Vendor" = the vendor of the PO(s) Accurate raised from the PPB line (PODET.REQID chain). */
class PpbVendorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function ppb(string $no, int $reqid): Ppb
    {
        return Ppb::create(['accurate_reqid' => $reqid, 'accurate_seq' => 0, 'no_ppb' => $no, 'tgl_ppb' => '2026-10-01', 'kode_barang' => 'BSP.0001',
            'deskripsi_barang' => 'BAUT', 'kuantitas' => 1, 'satuan' => 'PCS', 'status' => 'OPEN', 'qty_dipesan' => 1, 'qty_diterima' => 0]);
    }

    private function poFor(Ppb $ppb, Vendor $vendor): void
    {
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'SENT']);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'accurate_ppb_id' => $ppb->id, 'description_raw' => 'BAUT', 'qty' => 1, 'qty_received' => 0]);
    }

    public function test_list_shows_and_filters_by_the_vendor_of_the_po(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(\App\Models\Role::where('slug', 'admin_gudang')->firstOrFail());
        Sanctum::actingAs($user, ['app']);

        $andalan = Vendor::factory()->create(['name' => 'CV. ANDALAN TATA JAYA']);
        $global = Vendor::factory()->create(['name' => 'PT. GLOBAL HOSINDO']);
        Vendor::factory()->create(['name' => 'PT. TANPA PPB']); // has no PO from a PPB line

        $a = $this->ppb('PPB/NA/26/X/001', 1);
        $b = $this->ppb('PPB/NA/26/X/002', 2);
        $split = $this->ppb('PPB/NA/26/X/003', 3);
        $noPo = $this->ppb('PPB/NA/26/X/004', 4);
        $this->poFor($a, $andalan);
        $this->poFor($b, $global);
        $this->poFor($split, $andalan);
        $this->poFor($split, $global); // one line bought from two vendors

        $rows = collect($this->getJson('/api/ppb')->assertOk()->json('data'))->keyBy('no_ppb');
        $this->assertSame('CV. ANDALAN TATA JAYA', $rows[$a->no_ppb]['vendor']);
        $this->assertSame('CV. ANDALAN TATA JAYA, PT. GLOBAL HOSINDO', $rows[$split->no_ppb]['vendor']);
        $this->assertNull($rows[$noPo->no_ppb]['vendor'], 'no PO yet → no vendor');

        $filtered = collect($this->getJson('/api/ppb?vendor_id='.$global->id)->assertOk()->json('data'))->pluck('no_ppb')->sort()->values()->all();
        $this->assertSame([$b->no_ppb, $split->no_ppb], $filtered);

        $notOrdered = collect($this->getJson('/api/ppb?vendor_id=none')->assertOk()->json('data'))->pluck('no_ppb')->all();
        $this->assertSame([$noPo->no_ppb], $notOrdered, '"Belum ada PO" = lines with no PO yet');

        $searched = collect($this->getJson('/api/ppb?search=hosindo')->assertOk()->json('data'))->pluck('no_ppb')->sort()->values()->all();
        $this->assertSame([$b->no_ppb, $split->no_ppb], $searched, 'search also finds the vendor name');

        $options = collect($this->getJson('/api/ppb/vendor-options')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['CV. ANDALAN TATA JAYA', 'PT. GLOBAL HOSINDO'], $options, 'only vendors with a PO from a PPB line, by name');
    }
}
