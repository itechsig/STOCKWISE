<?php

namespace Tests\Feature\Purchasing;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PO list: search by more than the number, word by word, plus date and source filters. */
class PurchaseOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function po(string $number, string $vendor, string $date, array $lines, array $extra = []): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create(['number' => $number, 'date' => $date, 'vendor_id' => (Vendor::where('name', $vendor)->first() ?? Vendor::factory()->create(['name' => $vendor]))->id] + $extra);
        foreach ($lines as [$desc, $itemId]) {
            DB::table('purchase_order_items')->insert(['purchase_order_id' => $po->id, 'item_id' => $itemId, 'description_raw' => $desc, 'qty' => 1]);
        }

        return $po;
    }

    /** index() is now one row per item line (like PPB/RI) — dedupe to the distinct PO numbers matched, same as before the per-line change. */
    private function numbers(string $query): array
    {
        return collect($this->getJson('/api/purchase-orders?'.$query)->assertOk()->json('data'))->pluck('po_number')->unique()->sort()->values()->all();
    }

    public function test_search_any_field_word_by_word_and_filters(): void
    {
        $cam = Item::factory()->create(['code' => 'EAE.0291', 'description' => 'KAMERA CCTV HIKVISION']);
        $this->po('PO/NA/26/IX/001', 'PT SINAR JAYA', '2026-09-03', [['KAMERA CCTV HIKVISION', $cam->id]], ['accurate_po_id' => 501]);
        $this->po('PO/NA/26/IX/002', 'CV MAKMUR', '2026-09-20', [['KABEL UTP CAT6 BELDEN', null], ['BAUT M10 Ø12', null]], ['notes' => 'urgent proyek gedung A']);
        $this->po('PO/NA/26/X/003', 'PT SINAR JAYA', '2026-10-02', [['OLI MESIN 10W-40', null]]);

        $this->actingAsRole('purchasing');
        $this->assertSame(['PO/NA/26/IX/001', 'PO/NA/26/IX/002', 'PO/NA/26/X/003'], $this->numbers(''));
        $this->assertSame(['PO/NA/26/X/003'], $this->numbers('search=003'));                          // number, as before
        $this->assertSame(['PO/NA/26/IX/001', 'PO/NA/26/X/003'], $this->numbers('search=sinar'));      // vendor
        $this->assertSame(['PO/NA/26/IX/001'], $this->numbers('search=eae.0291'));                     // item code
        $this->assertSame(['PO/NA/26/IX/002'], $this->numbers('search=kabel belden'));                 // words of a line, any order
        $this->assertSame(['PO/NA/26/IX/002'], $this->numbers('search=baut O12'));                     // loose: O finds Ø
        $this->assertSame(['PO/NA/26/IX/002'], $this->numbers('search=gedung'));                       // notes
        $this->assertSame(['PO/NA/26/X/003'], $this->numbers('search=sinar oli'));                     // vendor + item, both needed
        $this->assertSame([], $this->numbers('search=sinar kabel'));

        $this->assertSame(['PO/NA/26/IX/002'], $this->numbers('date_from=2026-09-10&date_to=2026-09-30'));
        $this->assertSame(['PO/NA/26/IX/001'], $this->numbers('source=accurate'));
        $this->assertSame(['PO/NA/26/IX/002', 'PO/NA/26/X/003'], $this->numbers('source=internal'));
        $this->assertSame(['PO/NA/26/X/003'], $this->numbers('search=sinar&date_from=2026-10-01'));
    }
}
