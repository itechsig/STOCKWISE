<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Models\UsedReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pengembalian Bekas (UsedReturn) had zero rows despite the workflow being
 * real — nothing ever surfaced which Accurate records represented one. Per
 * explicit user instruction, the source is the RI mirror's own divisi "NV"
 * documents (real data: 2,534 lines across 1,017 invoices), taken as-is with
 * no extra keyword filtering. See AccurateSyncService::deriveUsedReturnsFromRi()
 * — runs off the ri table syncRi() just wrote, not Accurate staging directly.
 */
class AccurateUsedReturnFromRiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('accurate_apinv')) {
            Schema::create('accurate_apinv', function ($table) {
                $table->integer('APINVOICEID')->nullable();
                $table->string('INVOICENO')->nullable();
                $table->date('INVOICEDATE')->nullable();
                $table->string('PURCHASEORDERNO')->nullable();
                $table->date('SHIPDATE')->nullable();
                $table->string('DESCRIPTION')->nullable();
                $table->integer('VENDORID')->nullable();
            });
        }
        if (! Schema::hasTable('accurate_apitmdet')) {
            Schema::create('accurate_apitmdet', function ($table) {
                $table->integer('APINVOICEID')->nullable();
                $table->integer('SEQ')->nullable();
                $table->string('ITEMNO')->nullable();
                $table->string('ITEMOVDESC')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->string('ITEMUNIT')->nullable();
                $table->decimal('UNITPRICE', 14, 2)->nullable();
                $table->string('ITEMRESERVED3')->nullable();
                $table->integer('POID')->nullable();
                $table->integer('POSEQ')->nullable();
            });
        }
        if (! Schema::hasTable('accurate_persondata')) {
            Schema::create('accurate_persondata', function ($table) {
                $table->integer('ID')->nullable();
                $table->string('NAME')->nullable();
            });
        }

        Process::fake();
    }

    public function test_ri_nv_invoice_with_multiple_lines_becomes_one_used_return_with_matching_items(): void
    {
        // accurate_category_induk is normally derived by syncItems() itself from the
        // ITEMNO prefix (see KATEGORI_INDUK_MAP) — set directly here since these items
        // are seeded straight into `items`, not synced from an `accurate_item` fixture.
        Item::factory()->create(['code' => 'PUI.0001', 'accurate_category_induk' => 'Post-Use Items']);
        Item::factory()->create(['code' => 'OFN.0001', 'accurate_category_induk' => 'Office Needs']);

        DB::table('accurate_apinv')->insert(['APINVOICEID' => 500, 'INVOICENO' => 'RI/NV/26/IX/001', 'INVOICEDATE' => '2026-09-01', 'DESCRIPTION' => 'KEMBALI DARI PROJECT SINAR MULIA']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 500, 'SEQ' => 1, 'ITEMNO' => 'PUI.0001', 'ITEMOVDESC' => 'Barang Bekas Reusable', 'QUANTITY' => 2]);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 500, 'SEQ' => 2, 'ITEMNO' => 'OFN.0001', 'ITEMOVDESC' => 'Barang Biasa', 'QUANTITY' => 1]);

        // A non-NV invoice must NOT produce a UsedReturn.
        DB::table('accurate_apinv')->insert(['APINVOICEID' => 501, 'INVOICENO' => 'RI/ATK/26/IX/002', 'INVOICEDATE' => '2026-09-01']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 501, 'SEQ' => 1, 'ITEMNO' => 'OFN.0001', 'QUANTITY' => 1]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated()->assertJsonPath('data.status', 'SUCCESS');

        $ur = UsedReturn::where('accurate_apinvoice_id', 500)->first();
        $this->assertNotNull($ur, 'a UsedReturn must be derived from the RI/NV invoice');
        $this->assertSame('PENDING', $ur->status);
        $this->assertSame('RI/NV/26/IX/001', $ur->number, 'the RI\'s own number IS this record\'s number — no separate generated UR number');
        $this->assertNull($ur->npbg_ref_raw, 'this is RI-derived, not NPBG-derived — npbg_ref_raw must stay unset');
        $this->assertSame('KEMBALI DARI PROJECT SINAR MULIA', $ur->note);
        $this->assertSame(2, $ur->items()->count());

        $puiLine = $ur->items()->whereHas('item', fn ($q) => $q->where('code', 'PUI.0001'))->first();
        $this->assertSame('REUSABLE', $puiLine->condition);
        $this->assertTrue((bool) $puiLine->into_stock);

        $ofnLine = $ur->items()->whereHas('item', fn ($q) => $q->where('code', 'OFN.0001'))->first();
        $this->assertSame('USED', $ofnLine->condition);
        $this->assertFalse((bool) $ofnLine->into_stock);

        $this->assertNull(UsedReturn::where('accurate_apinvoice_id', 501)->first(), 'a non-NV invoice must not produce a UsedReturn');
    }

    public function test_resync_does_not_duplicate_the_derived_used_return(): void
    {
        Item::factory()->create(['code' => 'OFN.0002']);

        DB::table('accurate_apinv')->insert(['APINVOICEID' => 502, 'INVOICENO' => 'RI/NV/26/IX/003', 'INVOICEDATE' => '2026-09-01']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 502, 'SEQ' => 1, 'ITEMNO' => 'OFN.0002', 'QUANTITY' => 1]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();
        $this->assertSame(1, UsedReturn::where('accurate_apinvoice_id', 502)->count());

        $this->postJson('/api/sync/accurate')->assertCreated();
        $this->assertSame(1, UsedReturn::where('accurate_apinvoice_id', 502)->count(), 'resync must not create a duplicate UsedReturn');
    }

    public function test_two_different_invoices_sharing_the_same_ri_number_get_disambiguated(): void
    {
        // A real (if rare) data quirk in production: 5 pairs of genuinely
        // different Accurate AP invoices share the exact same no_ri text.
        // used_returns.number is unique, so the second must not fail the sync.
        Item::factory()->create(['code' => 'OFN.0003']);

        DB::table('accurate_apinv')->insert(['APINVOICEID' => 600, 'INVOICENO' => 'RI/NV/26/IX/010', 'INVOICEDATE' => '2026-09-01']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 600, 'SEQ' => 1, 'ITEMNO' => 'OFN.0003', 'QUANTITY' => 1]);
        DB::table('accurate_apinv')->insert(['APINVOICEID' => 601, 'INVOICENO' => 'RI/NV/26/IX/010', 'INVOICEDATE' => '2026-09-02']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 601, 'SEQ' => 1, 'ITEMNO' => 'OFN.0003', 'QUANTITY' => 1]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.status', 'SUCCESS');

        $first = UsedReturn::where('accurate_apinvoice_id', 600)->firstOrFail();
        $second = UsedReturn::where('accurate_apinvoice_id', 601)->firstOrFail();
        $this->assertSame('RI/NV/26/IX/010', $first->number);
        $this->assertSame('RI/NV/26/IX/010 (2)', $second->number);
    }
}
