<?php

namespace Tests\Feature\Accurate;

use App\Models\Npbg;
use App\Models\Ri;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Accurate input format since Sept 2026 (user confirmed 2026-10-05): requester /
 * checker = Department (DEPTID), ITEMRESERVED1/2 = warehouse / rack, NPBG SHIPVIA
 * = jenis pengeluaran. Older documents keep the old meaning.
 */
class AccurateNewInputFormatTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('accurate_arinv', function ($t) {
            $t->integer('ARINVOICEID');
            foreach (['INVOICENO', 'PURCHASEORDERNO', 'SHIPTO1', 'DESCRIPTION'] as $c) {
                $t->string($c)->nullable();
            }
            foreach (['INVOICEDATE', 'SHIPDATE', 'TAXDATE'] as $c) {
                $t->date($c)->nullable();
            }
            $t->integer('SHIPVIA')->nullable();
            $t->integer('TERMSID')->nullable();
        });
        Schema::create('accurate_termopmt', function ($t) {
            $t->integer('TERMID');
            $t->string('TERMNAME')->nullable();
            $t->string('TERMMEMO')->nullable();
        });
        Schema::create('accurate_arinvdet', function ($t) {
            $t->integer('ARINVOICEID');
            $t->integer('SEQ');
            foreach (['ITEMNO', 'ITEMOVDESC', 'ITEMUNIT', 'ITEMRESERVED1', 'ITEMRESERVED2'] as $c) {
                $t->string($c)->nullable();
            }
            $t->decimal('QUANTITY', 18, 4)->nullable();
            $t->integer('DEPTID')->nullable();
        });
        Schema::create('accurate_apinv', function ($t) {
            $t->integer('APINVOICEID');
            foreach (['INVOICENO', 'PURCHASEORDERNO', 'DESCRIPTION'] as $c) {
                $t->string($c)->nullable();
            }
            $t->date('INVOICEDATE')->nullable();
            $t->date('SHIPDATE')->nullable();
            $t->integer('VENDORID')->nullable();
        });
        Schema::create('accurate_apitmdet', function ($t) {
            $t->integer('APINVOICEID');
            $t->integer('SEQ');
            foreach (['ITEMNO', 'ITEMOVDESC', 'ITEMUNIT', 'ITEMRESERVED1', 'ITEMRESERVED2', 'ITEMRESERVED3'] as $c) {
                $t->string($c)->nullable();
            }
            $t->decimal('QUANTITY', 18, 4)->nullable();
            $t->decimal('UNITPRICE', 18, 4)->nullable();
            $t->integer('POID')->nullable();
            $t->integer('POSEQ')->nullable();
            $t->integer('DEPTID')->nullable();
        });
        Schema::create('accurate_persondata', fn ($t) => [$t->integer('ID')->nullable(), $t->string('NAME')->nullable()]);
        Schema::create('accurate_department', function ($t) {
            $t->integer('DEPTID');
            $t->string('DEPTNO')->nullable();
            $t->string('DEPTNAME')->nullable();
            $t->integer('ISSUBDEPT')->nullable();
        });
        Schema::create('accurate_shipment', fn ($t) => [$t->integer('SHIPID'), $t->string('NAME')->nullable()]);

        DB::table('accurate_department')->insert([
            ['DEPTID' => 1, 'DEPTNO' => '1000', 'DEPTNAME' => 'GUDANG', 'ISSUBDEPT' => 0],
            ['DEPTID' => 3, 'DEPTNO' => 'AYU', 'DEPTNAME' => 'GUDANG', 'ISSUBDEPT' => 1],
            ['DEPTID' => 33, 'DEPTNO' => 'NINING', 'DEPTNAME' => 'GENERAL SERVICES', 'ISSUBDEPT' => 1],
        ]);
        DB::table('accurate_shipment')->insert([['SHIPID' => 8, 'NAME' => 'UMUM'], ['SHIPID' => 11, 'NAME' => 'STPP']]);
        DB::table('accurate_termopmt')->insert([
            ['TERMID' => 1, 'TERMNAME' => 'C.O.D', 'TERMMEMO' => 'NON-PENJUALAN'], ['TERMID' => 2, 'TERMNAME' => 'Net 30', 'TERMMEMO' => 'PENJUALAN'],
            ['TERMID' => 3, 'TERMNAME' => 'Net 7', 'TERMMEMO' => null],
        ]);
        Process::fake();
    }

    public function test_npbg_and_ri_read_old_and_new_format(): void
    {
        // NPBG new format: department on the first line only, location in ITEMRESERVED1/2, SHIPVIA
        DB::table('accurate_arinv')->insert([
            ['ARINVOICEID' => 1, 'INVOICENO' => 'NA/26/X/022', 'INVOICEDATE' => '2026-10-03', 'PURCHASEORDERNO' => 'GENERAL SERVICES', 'SHIPVIA' => 8, 'TERMSID' => 1],
            ['ARINVOICEID' => 2, 'INVOICENO' => 'NA/26/VIII/220', 'INVOICEDATE' => '2026-08-31', 'PURCHASEORDERNO' => 'ADMIN TABUNG', 'SHIPVIA' => null, 'TERMSID' => 2],
            ['ARINVOICEID' => 3, 'INVOICENO' => 'NA/26/X/030', 'INVOICEDATE' => '2026-10-04', 'PURCHASEORDERNO' => 'GUDANG', 'SHIPVIA' => 11, 'TERMSID' => 3], // SHIPVIA only, no department
        ]);
        DB::table('accurate_arinvdet')->insert([
            ['ARINVOICEID' => 1, 'SEQ' => 1, 'ITEMNO' => 'HHN.0013', 'QUANTITY' => 1, 'DEPTID' => 33, 'ITEMRESERVED1' => 'GUDANG 1', 'ITEMRESERVED2' => 'RAK B.30'],
            ['ARINVOICEID' => 1, 'SEQ' => 2, 'ITEMNO' => 'HHN.0012', 'QUANTITY' => 1, 'DEPTID' => null, 'ITEMRESERVED1' => 'GUDANG 1', 'ITEMRESERVED2' => 'RAK F.4'],
            ['ARINVOICEID' => 2, 'SEQ' => 1, 'ITEMNO' => 'EAE.0286', 'QUANTITY' => 1, 'DEPTID' => null, 'ITEMRESERVED1' => 'JORGY', 'ITEMRESERVED2' => null],
            ['ARINVOICEID' => 3, 'SEQ' => 1, 'ITEMNO' => 'OFN.0001', 'QUANTITY' => 1, 'DEPTID' => null, 'ITEMRESERVED1' => 'GUDANG 2', 'ITEMRESERVED2' => 'RAK A.1'],
        ]);
        // RI: new (department), old (dates + checker), old without department but a location
        DB::table('accurate_apinv')->insert([
            ['APINVOICEID' => 10, 'INVOICENO' => 'RI/ATK/26/X/002', 'INVOICEDATE' => '2026-10-03'],
            ['APINVOICEID' => 11, 'INVOICENO' => 'RI/NA/26/VIII/080', 'INVOICEDATE' => '2026-08-26'],
            ['APINVOICEID' => 12, 'INVOICENO' => 'RI/NV/26/X/003', 'INVOICEDATE' => '2026-10-01'],
        ]);
        DB::table('accurate_apitmdet')->insert([
            ['APINVOICEID' => 10, 'SEQ' => 1, 'ITEMNO' => 'OFN.0165', 'QUANTITY' => 3, 'DEPTID' => 3, 'ITEMRESERVED1' => 'GUDANG 1', 'ITEMRESERVED2' => 'RAK A.17.4', 'ITEMRESERVED3' => null],
            ['APINVOICEID' => 11, 'SEQ' => 1, 'ITEMNO' => 'AUT.0714', 'QUANTITY' => 8, 'DEPTID' => null, 'ITEMRESERVED1' => '21/08/2026', 'ITEMRESERVED2' => '24/08/2026', 'ITEMRESERVED3' => 'AYU'],
            ['APINVOICEID' => 12, 'SEQ' => 2, 'ITEMNO' => 'SSP.0244', 'QUANTITY' => 1, 'DEPTID' => null, 'ITEMRESERVED1' => 'GUDANG 1', 'ITEMRESERVED2' => 'B.8.6', 'ITEMRESERVED3' => null],
        ]);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated()->assertJsonPath('data.error_records', 0);

        $n = fn ($id, $seq) => Npbg::where('accurate_arinvoice_id', $id)->where('accurate_seq', $seq)->first();
        $this->assertSame(['NINING', 'UMUM', 'GUDANG 1', 'RAK B.30'], [$n(1, 1)->peminta, $n(1, 1)->klasifikasi, $n(1, 1)->lokasi_gudang, $n(1, 1)->lokasi_rak]);
        $this->assertSame(['NINING', 'RAK F.4'], [$n(1, 2)->peminta, $n(1, 2)->lokasi_rak], 'department of the first line covers the document');
        $this->assertSame(['JORGY', null, null], [$n(2, 1)->peminta, $n(2, 1)->lokasi_gudang, $n(2, 1)->klasifikasi], 'old format unchanged');
        $this->assertSame([null, 'STPP', 'GUDANG 2'], [$n(3, 1)->peminta, $n(3, 1)->klasifikasi, $n(3, 1)->lokasi_gudang], 'new format without department: no requester, never the location');

        $r = fn ($id) => Ri::where('accurate_apinvoice_id', $id)->first();
        $this->assertSame(['AYU', 'GUDANG 1', 'RAK A.17.4'], [$r(10)->pemeriksa, $r(10)->lokasi_gudang, $r(10)->lokasi_rak]);
        $this->assertSame(['AYU', null, null], [$r(11)->pemeriksa, $r(11)->lokasi_gudang, $r(11)->lokasi_rak], 'old format: dates are not a location');
        $this->assertSame([null, 'GUDANG 1', 'B.8.6'], [$r(12)->pemeriksa, $r(12)->lokasi_gudang, $r(12)->lokasi_rak]);

        // Tipe NPBG [Terms]: TERMOPMT memo; a term without memo stays empty
        $this->assertSame(['Non Penjualan', 'Non Penjualan', 'Penjualan', null], [$n(1, 1)->tipe_npbg, $n(1, 2)->tipe_npbg, $n(2, 1)->tipe_npbg, $n(3, 1)->tipe_npbg]);
        $this->assertSame(['NA/26/VIII/220'], collect($this->getJson('/api/npbg?tipe_npbg=Penjualan')->assertOk()->json('data'))->pluck('no_npbg')->all());
        // it follows Accurate: an edit through the API doesn't stick on an Accurate NPBG
        $this->putJson('/api/npbg/'.$n(2, 1)->id, ['tipe_npbg' => 'Non Penjualan'])->assertOk();
        $this->assertSame('Penjualan', $n(2, 1)->tipe_npbg);

        // list filter + options
        $this->assertSame(['STPP', 'UMUM'], $this->getJson('/api/npbg/klasifikasi')->assertOk()->json('data'));
        $this->assertSame(['NA/26/X/030'], collect($this->getJson('/api/npbg?klasifikasi=STPP')->assertOk()->json('data'))->pluck('no_npbg')->all());
    }

    public function test_template_fields_klasifikasi_no_seri_and_ri_header(): void
    {
        Schema::table('accurate_arinvdet', fn ($t) => $t->integer('ITEMHISTID')->nullable());
        Schema::table('accurate_apitmdet', fn ($t) => $t->integer('ITEMHISTID')->nullable());
        Schema::table('accurate_apinv', function ($t) {
            $t->integer('SHIPVIA')->nullable();
            $t->string('FOB')->nullable();
            $t->string('SEQUENCENO')->nullable();
        });
        Schema::create('accurate_snline_byitemhist', fn ($t) => [$t->integer('ITEMHISTID'), $t->text('SNLINE')->nullable()]);
        Schema::create('accurate_podet', fn ($t) => [$t->integer('POID'), $t->integer('SEQ'), $t->integer('REQID')->nullable()]);
        Schema::create('accurate_requisition', fn ($t) => [$t->integer('REQID'), $t->string('REQNO')->nullable()]);

        DB::table('accurate_arinv')->insert(['ARINVOICEID' => 1, 'INVOICENO' => 'ATK/26/X/003', 'INVOICEDATE' => '2026-10-02', 'SHIPVIA' => 8]);
        DB::table('accurate_arinvdet')->insert([
            ['ARINVOICEID' => 1, 'SEQ' => 1, 'ITEMNO' => 'OFN.0038', 'QUANTITY' => 5, 'DEPTID' => 3, 'ITEMHISTID' => 700],
            ['ARINVOICEID' => 1, 'SEQ' => 2, 'ITEMNO' => 'OFN.0001', 'QUANTITY' => 1, 'DEPTID' => 3, 'ITEMHISTID' => 701],
        ]);
        DB::table('accurate_apinv')->insert([
            // the supplier's surat jalan typed in Invoice No: the RI number is in Form No
            ['APINVOICEID' => 10, 'INVOICENO' => 'SRT-07965', 'SEQUENCENO' => 'RI/NV/26/IX/050', 'INVOICEDATE' => '2026-09-29', 'SHIPVIA' => null, 'FOB' => 'Shipping Point'],
            // the usual: the RI number in Invoice No, Form No just a counter — no surat jalan
            ['APINVOICEID' => 11, 'INVOICENO' => 'RI/NA/26/X/010', 'SEQUENCENO' => '001', 'INVOICEDATE' => '2026-10-03', 'SHIPVIA' => 11, 'FOB' => null],
        ]);
        DB::table('accurate_apitmdet')->insert([
            ['APINVOICEID' => 10, 'SEQ' => 1, 'ITEMNO' => 'SSP.0244', 'QUANTITY' => 1, 'DEPTID' => null, 'ITEMHISTID' => 800, 'POID' => null, 'POSEQ' => null],
            ['APINVOICEID' => 11, 'SEQ' => 1, 'ITEMNO' => 'MAI.0066', 'QUANTITY' => 3, 'DEPTID' => 3, 'ITEMHISTID' => 801, 'POID' => 1916, 'POSEQ' => 0],
        ]);
        DB::table('accurate_snline_byitemhist')->insert([['ITEMHISTID' => 700, 'SNLINE' => '091801-091850 ; 091851-091900'], ['ITEMHISTID' => 800, 'SNLINE' => 'SIG-012 ; SIG-109']]);
        DB::table('accurate_podet')->insert(['POID' => 1916, 'SEQ' => 0, 'REQID' => 77]);
        DB::table('accurate_requisition')->insert(['REQID' => 77, 'REQNO' => 'PPB/NA/26/IX/040']);

        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/sync/accurate')->assertCreated()->assertJsonPath('data.error_records', 0);

        $n = fn ($seq) => Npbg::where('accurate_arinvoice_id', 1)->where('accurate_seq', $seq)->first();
        $this->assertSame(['UMUM', '091801-091850 ; 091851-091900'], [$n(1)->klasifikasi, $n(1)->no_seri]);
        $this->assertSame([null, 'AYU'], [$n(2)->no_seri, $n(2)->peminta]);

        $r = fn ($id) => Ri::where('accurate_apinvoice_id', $id)->first();
        $this->assertSame(['SRT-07965', null, 'Shipping Point', null, 'SIG-012 ; SIG-109'], [$r(10)->no_surat_jalan, $r(10)->pengirim, $r(10)->fob, $r(10)->no_ppb, $r(10)->no_seri]);
        $this->assertSame([null, 'RI/NA/26/X/010', 'STPP', null, 'PPB/NA/26/IX/040'], [$r(11)->no_surat_jalan, $r(11)->no_ri, $r(11)->pengirim, $r(11)->fob, $r(11)->no_ppb]);
        $this->assertSame(['RI/NV/26/IX/050', 'NV'], [$r(10)->no_ri, $r(10)->divisi], 'the RI number is the Form No, not the surat jalan');

        // the source tables missing on a later sync never wipe what is there
        Schema::drop('accurate_snline_byitemhist');
        Schema::drop('accurate_shipment');
        DB::table('accurate_arinvdet')->where('ARINVOICEID', 1)->where('SEQ', 1)->update(['QUANTITY' => 6]);
        $this->postJson('/api/sync/accurate')->assertCreated();
        $this->assertSame(['UMUM', '091801-091850 ; 091851-091900', 6.0], [$n(1)->klasifikasi, $n(1)->no_seri, (float) $n(1)->kuantitas]);

        // klasifikasi is Accurate-owned now: not editable
        $this->patchJson('/api/npbg/'.$n(1)->id, ['klasifikasi' => 'X', 'no_seri' => 'Y'])->assertOk();
        $this->assertSame(['UMUM', '091801-091850 ; 091851-091900'], [$n(1)->klasifikasi, $n(1)->no_seri]);
    }
}
