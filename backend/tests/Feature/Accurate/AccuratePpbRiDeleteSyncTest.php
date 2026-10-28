<?php

namespace Tests\Feature\Accurate;

use App\Models\Ppb;
use App\Models\Ri;
use App\Models\SyncLog;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PPB/RI must end up an exact mirror of Accurate (explicit user instruction,
 * see AccurateSyncService::deleteOrphans()) — a row Accurate no longer has is
 * hard-deleted here too on the next sync, not just left stale. Focused on the
 * delete-detection path only; field-mapping correctness for PPB/RI has no
 * existing test coverage to extend here.
 */
class AccuratePpbRiDeleteSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('accurate_requisition')) {
            Schema::create('accurate_requisition', function ($table) {
                $table->integer('REQID')->nullable();
                $table->string('REQNO')->nullable();
                $table->date('REQDATE')->nullable();
                $table->boolean('ISCLOSED')->nullable();
                $table->string('DESCRIPTION')->nullable();
            });
        }
        if (! Schema::hasTable('accurate_requisitiondet')) {
            Schema::create('accurate_requisitiondet', function ($table) {
                $table->integer('REQID')->nullable();
                $table->integer('SEQ')->nullable();
                $table->string('ITEMNO')->nullable();
                $table->string('ITEMOVDESC')->nullable();
                $table->decimal('QUANTITY', 14, 2)->nullable();
                $table->string('ITEMUNIT')->nullable();
                $table->decimal('QTYORDERED', 14, 2)->nullable();
                $table->decimal('QTYRECEIVED', 14, 2)->nullable();
                $table->string('ITEMRESERVED3')->nullable();
                $table->string('NOTES')->nullable();
            });
        }
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

    public function test_ppb_row_deleted_in_accurate_is_deleted_in_stockwise_on_resync(): void
    {
        DB::table('accurate_requisition')->insert(['REQID' => 70, 'REQNO' => 'PPB/ATK/26/IX/001', 'REQDATE' => '2026-09-01', 'ISCLOSED' => false, 'DESCRIPTION' => 'Test PPB']);
        DB::table('accurate_requisitiondet')->insert(['REQID' => 70, 'SEQ' => 1, 'ITEMNO' => 'OFN.0001', 'ITEMOVDESC' => 'Kertas', 'QUANTITY' => 5]);
        // A second, untouched requisition so the resync's fetch legitimately
        // returns data — proving this is a real deletion, not the "fetch came
        // back empty" case guarded separately in AccurateNpbgSyncTest.
        DB::table('accurate_requisition')->insert(['REQID' => 71, 'REQNO' => 'PPB/ATK/26/IX/002', 'REQDATE' => '2026-09-01', 'ISCLOSED' => false]);
        DB::table('accurate_requisitiondet')->insert(['REQID' => 71, 'SEQ' => 1, 'ITEMNO' => 'OFN.0002', 'QUANTITY' => 1]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 2);
        $ppb = Ppb::where('accurate_reqid', 70)->firstOrFail();

        DB::table('accurate_requisitiondet')->where('REQID', 70)->delete();
        DB::table('accurate_requisition')->where('REQID', 70)->delete();

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.deleted_records', 1);

        $this->assertModelMissing($ppb);
        $log = SyncLog::where('entity', 'ppb')->where('action', 'DELETE')->where('source_id', '70-1')->firstOrFail();
        $this->assertSame('Kertas', $log->old_data['deskripsi_barang']);
    }

    public function test_ri_row_deleted_in_accurate_is_deleted_in_stockwise_on_resync(): void
    {
        DB::table('accurate_apinv')->insert(['APINVOICEID' => 80, 'INVOICENO' => 'RI/NV/26/IX/001', 'INVOICEDATE' => '2026-09-01']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 80, 'SEQ' => 1, 'ITEMNO' => 'OFN.0002', 'ITEMOVDESC' => 'Tinta', 'QUANTITY' => 3]);
        // A second, untouched AP invoice so the resync's fetch legitimately
        // returns data — proving this is a real deletion, not the "fetch came
        // back empty" case guarded separately in AccurateNpbgSyncTest.
        DB::table('accurate_apinv')->insert(['APINVOICEID' => 81, 'INVOICENO' => 'RI/NV/26/IX/002', 'INVOICEDATE' => '2026-09-01']);
        DB::table('accurate_apitmdet')->insert(['APINVOICEID' => 81, 'SEQ' => 1, 'ITEMNO' => 'OFN.0003', 'QUANTITY' => 1]);

        $this->actingAsRole('admin_gudang');
        // 4, not 2: both invoices are divisi NV, so each RI line insert also
        // derives a UsedReturn header (see AccurateUsedReturnFromRiTest) — the
        // aggregate inserted_records counts every entity type in one sync run.
        $this->postJson('/api/sync/accurate')->assertJsonPath('data.inserted_records', 4);
        $ri = Ri::where('accurate_apinvoice_id', 80)->firstOrFail();

        DB::table('accurate_apitmdet')->where('APINVOICEID', 80)->delete();
        DB::table('accurate_apinv')->where('APINVOICEID', 80)->delete();

        $res = $this->postJson('/api/sync/accurate')->assertCreated();
        $res->assertJsonPath('data.deleted_records', 1);

        $this->assertModelMissing($ri);
        $log = SyncLog::where('entity', 'ri')->where('action', 'DELETE')->where('source_id', '80-1')->firstOrFail();
        $this->assertSame('Tinta', $log->old_data['deskripsi_barang']);
    }
}
