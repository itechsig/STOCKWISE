<?php

namespace Tests\Feature\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\StppTransaction;
use App\Models\TrackingPhoto;
use App\Services\Tracking\Import\StppExcelImporter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** "4. Tracking STPP.xlsx" + DATA BUKTI → STPP, merged with the STPP rows made from Accurate. */
class StppExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_units_take_over_accurate_rows_and_bring_their_photos(): void
    {
        Storage::fake('local');
        $line = Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/X/138', 'tgl_npbg' => '2025-10-01',
            'kode_barang' => 'T.1', 'deskripsi_barang' => 'GERINDA TANGAN', 'kuantitas' => 2, 'satuan' => 'PCS', 'pelanggan' => 'MAINTENANCE', 'keterangan' => 'UNTUK STPP']);
        Ri::forceCreate(['accurate_apinvoice_id' => 1, 'accurate_seq' => 0, 'no_ri' => 'RI/NV/25/XI/051', 'tgl_ri' => '2025-11-03', 'divisi' => 'NV',
            'kode_barang' => 'T.1', 'deskripsi_barang' => 'GERINDA TANGAN', 'kuantitas' => 1, 'satuan' => 'PCS', 'vendor' => 'MAINTENANCE']);
        // what the Accurate sync already made from that NPBG line
        $derived = StppTransaction::create(['number' => 'STPP/SDA/25/X/900', 'description_raw' => 'GERINDA TANGAN', 'qty' => 2,
            'out_npbg_id' => $line->id, 'out_date' => '2025-10-01', 'status' => 'ACTIVE', 'source' => 'ACCURATE']);

        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('STPP');
        $ws->fromArray([1, 'SN-0001', 'GERINDA TANGAN', 1, 'PCS', 'TOMY', 'MAINTENANCE MA', 45931, 'NA/25/X/138', 1, 'NA.25.X.138-1', null,
            'UNTUK STPP', null, 45964, 'RI/NV/25/XI/051', 1, 'RI.NV.25.XI.051-1', null, 'KEMBALI RUSAK'], null, 'B6');
        $ws->fromArray([2, 'SN-0002', 'GERINDA TANGAN', 1, 'PCS', 'AGUS', 'GUDANG', 45931, 'NA/25/X/138', 2, 'NA.25.X.138-2', null, 'UNTUK STPP'], null, 'B7');
        $m = $book->createSheet()->setTitle('Maintenance');
        $m->fromArray([1, 'SN-0001', null, null, null, 'KEVIN', 45940, 'NA/25/X/138', 'UNTUK REFILL'], null, 'B6');
        $path = tempnam(sys_get_temp_dir(), 'stpp').'.xlsx';
        (new Xlsx($book))->save($path);

        $dir = sys_get_temp_dir().'/stpp-'.uniqid();
        mkdir("{$dir}/Pengeluaran", 0777, true);
        mkdir("{$dir}/Penerimaan", 0777, true);
        file_put_contents("{$dir}/Pengeluaran/NA.25.X.138-1.jpg", 'out1');
        file_put_contents("{$dir}/Pengeluaran/NA.25.X.138-2.jpg", 'out2');
        file_put_contents("{$dir}/Penerimaan/RI.NV.25.XI.051.jpg", 'doc photo'); // one photo for the whole RI

        $importer = app(StppExcelImporter::class);
        $st = $importer->import($path, $dir);
        $this->assertSame([2, 2, 1, 2, 1, 1], [$st['rows'], $st['created'], $st['replaced_accurate'], $st['photo_out'], $st['photo_in'], $st['maintenance_rows']]);
        $this->assertNull($derived->fresh(), 'the row derived from Accurate is replaced by the Excel rows of that NPBG');
        $this->assertSame(0, $importer->import($path, $dir)['created'], 're-run updates');
        $this->assertSame(2, StppTransaction::count(), 'no duplicate of the Accurate row');
        // the Accurate sync does not derive that NPBG again
        $this->postJson('/api/sync/accurate');
        $this->assertSame(2, StppTransaction::count());

        $first = StppTransaction::where('serial_no_raw', 'SN-0001')->sole();
        $this->assertSame('SN-0001', $first->serial_no_raw);
        $this->assertSame('PASSIVE', $first->status, 'Tanda Kembali + Bukti Terima (formula; no cached value in the test file)');
        $this->assertSame('RI/NV/25/XI/051', $first->return_ri_no);
        $second = StppTransaction::where('serial_no_raw', 'SN-0002')->sole();
        $this->assertSame([$line->id, 'ACTIVE'], [$second->out_npbg_id, $second->status]);
        $this->assertSame(3, TrackingPhoto::where('entity', 'stpp')->count());

        $this->actingAsRole('admin_gudang');
        $row = $this->getJson('/api/stpp?search=SN-0001')->assertOk()->json('data.0');
        $this->assertSame(['NA/25/X/138', true, 'RI/NV/25/XI/051', true], [$row['out_npbg'], $row['npbg_linked'], $row['return_ri'], $row['ri_linked']]);
        $this->assertTrue(isset($row['photos']['out'], $row['photos']['in']));
        $this->getJson("/api/stpp/{$first->id}")->assertJsonPath('data.maintenance.0.note', 'UNTUK REFILL')->assertJsonPath('data.history.0.serial_no_raw', null);
        $this->getJson('/api/stpp-maintenance')->assertJsonPath('data.0.description', 'GERINDA TANGAN')->assertJsonPath('data.0.npbg_linked', true);

        // a new unit / a withdrawal by RI number, from the app
        $new = $this->postJson('/api/stpp', ['serial_no' => 'SN-0003', 'description_raw' => 'BOR', 'out_npbg_no' => 'na/25/x/138', 'placement_raw' => 'GUDANG'])
            ->assertCreated()->json('data');
        $this->assertSame([3, 'NA/25/X/138', true], [$new['no'], $new['out_npbg'], $new['npbg_linked']]);
        $this->postJson("/api/stpp/{$new['id']}/withdraw", ['return_ri_no' => 'RI/NV/25/XI/051'])->assertOk()->assertJsonPath('data.status', 'PASSIVE');
        $this->postJson('/api/stpp', ['serial_no' => 'SN-9', 'out_npbg_no' => 'NA/99/I/999'])->assertStatus(422)->assertJsonValidationErrors('out_npbg_no');

        @unlink($path);
        array_map('unlink', glob("{$dir}/*/*"));
    }
}
