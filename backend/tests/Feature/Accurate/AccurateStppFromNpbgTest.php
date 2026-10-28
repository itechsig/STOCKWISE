<?php

namespace Tests\Feature\Accurate;

use App\Models\Item;
use App\Models\Npbg;
use App\Models\StppTransaction;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STPP (docs/status-flow.md §8) had zero rows despite the workflow being
 * real — nothing ever surfaced which NPBG lines were STPP placements, per
 * explicit user instruction those are the NPBG lines whose own `keterangan`
 * mentions "STPP" (real production data already has ~189 of them). See
 * AccurateSyncService::deriveStppFromNpbg() — runs right after syncNpbg(),
 * off the npbg table it just wrote, not off Accurate staging tables.
 */
class AccurateStppFromNpbgTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('accurate_arinv')) {
            Schema::create('accurate_arinv', function ($table) {
                $table->integer('ARINVOICEID')->nullable();
                $table->string('INVOICENO')->nullable();
                $table->date('INVOICEDATE')->nullable();
                $table->date('SHIPDATE')->nullable();
                $table->date('TAXDATE')->nullable();
                $table->string('PURCHASEORDERNO')->nullable();
                $table->string('SHIPTO1')->nullable();
                $table->string('DESCRIPTION')->nullable();
            });
        }
        if (! Schema::hasTable('accurate_arinvdet')) {
            Schema::create('accurate_arinvdet', function ($table) {
                $table->integer('ARINVOICEID')->nullable();
                $table->integer('SEQ')->nullable();
                $table->string('ITEMNO')->nullable();
                $table->string('ITEMOVDESC')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->string('ITEMUNIT')->nullable();
                $table->string('ITEMRESERVED1')->nullable();
            });
        }

        Process::fake();
    }

    public function test_npbg_line_mentioning_stpp_creates_an_stpp_transaction(): void
    {
        $item = Item::factory()->create(['code' => 'AUT.0373']);
        Unit::factory()->create(['code' => 'PCS']);

        // keterangan is header-level (ARINV.DESCRIPTION), shared by every line under
        // that invoice — real production data confirms this: one multi-line NPBG
        // invoice mentioning STPP genuinely has ALL its lines meant for STPP.
        DB::table('accurate_arinv')->insert([
            'ARINVOICEID' => 190, 'INVOICENO' => 'NA/25/X/190', 'INVOICEDATE' => '2025-10-20',
            'DESCRIPTION' => 'UNTUK DIJADIKAN STPP GUDANG GUNA PEMAKAIAN MAINTENANCE',
        ]);
        DB::table('accurate_arinvdet')->insert([
            'ARINVOICEID' => 190, 'SEQ' => 1, 'ITEMNO' => 'AUT.0373',
            'ITEMOVDESC' => 'MANUAL LUBRICATOR GREASE', 'QUANTITY' => 1, 'ITEMUNIT' => 'PCS', 'ITEMRESERVED1' => 'AYU',
        ]);

        // A separate invoice with ordinary keterangan — must NOT produce an STPP row.
        DB::table('accurate_arinv')->insert([
            'ARINVOICEID' => 191, 'INVOICENO' => 'NA/25/X/191', 'INVOICEDATE' => '2025-10-20',
            'DESCRIPTION' => 'UNTUK PEMAKAIAN HARIAN GUDANG',
        ]);
        DB::table('accurate_arinvdet')->insert([
            'ARINVOICEID' => 191, 'SEQ' => 1, 'ITEMNO' => 'OFN.0001', 'ITEMOVDESC' => 'KERTAS HVS', 'QUANTITY' => 2,
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated()->assertJsonPath('data.status', 'SUCCESS');

        $npbg = Npbg::where('accurate_arinvoice_id', 190)->where('accurate_seq', 1)->firstOrFail();
        $stpp = StppTransaction::where('out_npbg_id', $npbg->id)->first();
        $this->assertNotNull($stpp, 'an STPP row must be derived from the NPBG line whose keterangan mentions STPP');
        $this->assertSame('ACTIVE', $stpp->status);
        $this->assertSame($item->id, $stpp->item_id);
        $this->assertSame('AYU', $stpp->holder_name_raw);
        $this->assertEquals(1, $stpp->qty);
        $this->assertSame('UNTUK DIJADIKAN STPP GUDANG GUNA PEMAKAIAN MAINTENANCE', $stpp->out_note);

        $unrelated = Npbg::where('accurate_arinvoice_id', 191)->firstOrFail();
        $this->assertNull(
            StppTransaction::where('out_npbg_id', $unrelated->id)->first(),
            'a line whose keterangan does not mention STPP must not produce one'
        );
    }

    public function test_resync_does_not_duplicate_the_derived_stpp_row(): void
    {
        Item::factory()->create(['code' => 'AUT.0373']);

        DB::table('accurate_arinv')->insert([
            'ARINVOICEID' => 191, 'INVOICENO' => 'NA/25/X/191', 'INVOICEDATE' => '2025-10-21',
            'DESCRIPTION' => 'UNTUK DIJADIKAN STPP GUDANG',
        ]);
        DB::table('accurate_arinvdet')->insert([
            'ARINVOICEID' => 191, 'SEQ' => 1, 'ITEMNO' => 'AUT.0373', 'ITEMOVDESC' => 'Alat', 'QUANTITY' => 1,
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();
        $npbg = Npbg::where('accurate_arinvoice_id', 191)->firstOrFail();
        $this->assertSame(1, StppTransaction::where('out_npbg_id', $npbg->id)->count());

        $this->postJson('/api/sync/accurate')->assertCreated();
        $this->assertSame(1, StppTransaction::where('out_npbg_id', $npbg->id)->count(), 'resync must not create a duplicate STPP row');
    }
}
