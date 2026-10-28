<?php

namespace Tests\Feature\Accurate;

use App\Models\Ppb;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PPB divisi = Accurate Department (REQUISITIONDET.DEPTID -> DEPARTMENT.DEPTNAME);
 * "PPB/NA/..", "PPB/ATK/.." is only a numbering code (fallback for old PPBs).
 * Requester and department are typed on the first line only in Accurate and
 * carried over to every line of the PPB. Shapes verified against the real
 * staging data (backup 2026-09-26).
 */
class AccuratePpbDivisiTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('accurate_requisition');
        Schema::dropIfExists('accurate_requisitiondet');
        Schema::dropIfExists('accurate_department');
        Schema::create('accurate_requisition', function ($table) {
            $table->integer('REQID')->nullable();
            $table->string('REQNO')->nullable();
            $table->date('REQDATE')->nullable();
            $table->boolean('ISCLOSED')->nullable();
            $table->string('DESCRIPTION')->nullable();
        });
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
            $table->integer('DEPTID')->nullable();
        });
        Schema::create('accurate_department', function ($table) {
            $table->integer('DEPTID');
            $table->string('DEPTNO')->nullable();
            $table->string('DEPTNAME')->nullable();
        });

        Process::fake();
    }

    private function sync(): void
    {
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated();
    }

    public function test_divisi_comes_from_the_accurate_department_and_requester_is_carried_to_every_line(): void
    {
        DB::table('accurate_department')->insert([
            ['DEPTID' => 3, 'DEPTNO' => 'GDG', 'DEPTNAME' => 'GUDANG'],
            ['DEPTID' => 17, 'DEPTNO' => 'MTC', 'DEPTNAME' => 'MAINTENANCE'],
        ]);
        DB::table('accurate_requisition')->insert([
            ['REQID' => 500, 'REQNO' => 'PPB/NA/26/X/001', 'REQDATE' => '2026-10-02', 'ISCLOSED' => false, 'DESCRIPTION' => 'Stok gudang'],
            ['REQID' => 501, 'REQNO' => 'PPB/NA/26/X/002', 'REQDATE' => '2026-10-02', 'ISCLOSED' => false, 'DESCRIPTION' => 'Perbaikan'],
        ]);
        DB::table('accurate_requisitiondet')->insert([
            // requester + department on the first line only (as typed in Accurate)
            ['REQID' => 500, 'SEQ' => 0, 'ITEMNO' => 'SSP.0001', 'QUANTITY' => 1, 'ITEMRESERVED3' => 'JORDY', 'DEPTID' => 3],
            ['REQID' => 500, 'SEQ' => 1, 'ITEMNO' => 'SSP.0002', 'QUANTITY' => 2, 'ITEMRESERVED3' => null, 'DEPTID' => null],
            ['REQID' => 501, 'SEQ' => 0, 'ITEMNO' => 'SSP.0003', 'QUANTITY' => 1, 'ITEMRESERVED3' => 'FIAN', 'DEPTID' => 17],
        ]);

        $this->sync();

        $lines = Ppb::where('accurate_reqid', 500)->orderBy('accurate_seq')->get();
        $this->assertSame(['GUDANG', 'GUDANG'], $lines->pluck('divisi')->all());
        $this->assertSame(['JORDY', 'JORDY'], $lines->pluck('peminta')->all());
        $this->assertSame('MAINTENANCE', Ppb::where('accurate_reqid', 501)->value('divisi'));
    }

    public function test_new_format_requester_is_the_department_person_on_every_line(): void
    {
        // shapes from the real staging data 2026-10-14 (PPB/NA/26/X/012: ITEMRESERVED3 empty, DEPTID 17 = JORDY)
        Schema::table('accurate_department', fn ($t) => $t->boolean('ISSUBDEPT')->nullable());
        DB::table('accurate_department')->insert([
            ['DEPTID' => 1, 'DEPTNO' => '1000', 'DEPTNAME' => 'GUDANG', 'ISSUBDEPT' => false],
            ['DEPTID' => 17, 'DEPTNO' => 'JORDY', 'DEPTNAME' => 'GUDANG', 'ISSUBDEPT' => true],
            ['DEPTID' => 42, 'DEPTNO' => 'CHINDY', 'DEPTNAME' => 'GUDANG', 'ISSUBDEPT' => true],
        ]);
        DB::table('accurate_requisition')->insert([
            ['REQID' => 665, 'REQNO' => 'PPB/NA/26/X/012', 'REQDATE' => '2026-10-14', 'ISCLOSED' => false],
            ['REQID' => 666, 'REQNO' => 'PPB/NA/26/X/013', 'REQDATE' => '2026-10-14', 'ISCLOSED' => false],
            ['REQID' => 667, 'REQNO' => 'PPB/NA/26/X/014', 'REQDATE' => '2026-10-14', 'ISCLOSED' => false],
        ]);
        DB::table('accurate_requisitiondet')->insert([
            ['REQID' => 665, 'SEQ' => 0, 'ITEMNO' => 'MAI.0069', 'QUANTITY' => 10, 'ITEMRESERVED3' => null, 'DEPTID' => 17],
            ['REQID' => 665, 'SEQ' => 1, 'ITEMNO' => 'MAI.0142', 'QUANTITY' => 10, 'ITEMRESERVED3' => '', 'DEPTID' => null],
            ['REQID' => 665, 'SEQ' => 2, 'ITEMNO' => 'MAI.0813', 'QUANTITY' => 2, 'ITEMRESERVED3' => null, 'DEPTID' => 42],
            ['REQID' => 666, 'SEQ' => 0, 'ITEMNO' => 'MAI.0001', 'QUANTITY' => 1, 'ITEMRESERVED3' => null, 'DEPTID' => 1],
            ['REQID' => 667, 'SEQ' => 0, 'ITEMNO' => 'MAI.0002', 'QUANTITY' => 1, 'ITEMRESERVED3' => 'AYU', 'DEPTID' => 17],
        ]);

        $this->sync();

        $p = fn ($req) => Ppb::where('accurate_reqid', $req)->orderBy('accurate_seq')->pluck('peminta')->all();
        $this->assertSame(['JORDY', 'JORDY', 'CHINDY'], $p(665), 'own department first, else the PPB\'s first one');
        $this->assertSame([null], $p(666), 'a parent department is not a person');
        $this->assertSame(['AYU'], $p(667), 'a typed requester still wins');
    }

    public function test_old_ppb_without_department_keeps_the_number_segment(): void
    {
        DB::table('accurate_requisition')->insert(['REQID' => 2, 'REQNO' => 'PPB/ATK/25/IX/004', 'REQDATE' => '2025-09-29', 'ISCLOSED' => true]);
        DB::table('accurate_requisitiondet')->insert(['REQID' => 2, 'SEQ' => 0, 'ITEMNO' => 'OFN.0118', 'QUANTITY' => 30]);

        $this->sync();

        $this->assertSame('ATK', Ppb::where('accurate_reqid', 2)->value('divisi'));
        $this->assertNull(Ppb::where('accurate_reqid', 2)->value('peminta'));
    }

    public function test_sync_never_deletes_a_manual_ppb_but_still_removes_a_line_gone_from_accurate(): void
    {
        // a PPB made in Stockwise (Tambah PPB / Request Barang): no Accurate id
        $manual = Ppb::create(['no_ppb' => 'PPB/MANUAL/26/X/001', 'tgl_ppb' => '2026-10-20', 'deskripsi_barang' => 'KERTAS HVS', 'kuantitas' => 5, 'status' => 'OPEN']);
        $manual2 = Ppb::create(['no_ppb' => 'PPB/MANUAL/26/X/002', 'tgl_ppb' => '2026-10-20', 'deskripsi_barang' => 'BOLPEN', 'kuantitas' => 2, 'status' => 'OPEN']);
        DB::table('accurate_requisition')->insert(['REQID' => 700, 'REQNO' => 'PPB/NA/26/X/030', 'REQDATE' => '2026-10-20', 'ISCLOSED' => false]);
        DB::table('accurate_requisitiondet')->insert([
            ['REQID' => 700, 'SEQ' => 0, 'ITEMNO' => 'SSP.0001', 'QUANTITY' => 1],
            ['REQID' => 700, 'SEQ' => 1, 'ITEMNO' => 'SSP.0002', 'QUANTITY' => 1],
        ]);
        $this->sync();
        DB::table('accurate_requisitiondet')->where(['REQID' => 700, 'SEQ' => 1])->delete(); // deleted in Accurate
        $this->sync();
        $this->sync();

        $this->assertTrue(Ppb::whereKey([$manual->id, $manual2->id])->count() === 2, 'manual PPBs survive every sync');
        $this->assertSame([0], Ppb::where('accurate_reqid', 700)->pluck('accurate_seq')->all(), 'a line deleted in Accurate still goes');
    }

    public function test_department_table_not_mirrored_yet_falls_back_safely(): void
    {
        Schema::drop('accurate_department');
        DB::table('accurate_requisition')->insert(['REQID' => 600, 'REQNO' => 'PPB/NA/26/X/009', 'REQDATE' => '2026-10-09', 'ISCLOSED' => false]);
        DB::table('accurate_requisitiondet')->insert(['REQID' => 600, 'SEQ' => 0, 'ITEMNO' => 'SSP.0009', 'QUANTITY' => 1, 'DEPTID' => 3]);

        $this->sync();

        $this->assertSame('NA', Ppb::where('accurate_reqid', 600)->value('divisi'));
    }
}
