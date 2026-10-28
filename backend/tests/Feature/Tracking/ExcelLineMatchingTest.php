<?php

namespace Tests\Feature\Tracking;

use App\Models\Npbg;
use App\Models\StppTransaction;
use App\Models\TyreChange;
use App\Services\Tracking\Import\StppExcelImporter;
use App\Services\Tracking\Import\TyreExcelImporter;
use App\Services\Tracking\TyreFromAccurate;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * An Excel row is matched to the NPBG line it stands for even when the Excel
 * writes the description differently, and two rows never share a line that has
 * room for one — found by "cek all logic" on the real data (NA/25/X/108: two
 * tyres, two lines; NA/25/XII/139: welding cables).
 */
class ExcelLineMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function npbg(int $id, string $no, int $seq, string $desc, float $qty, string $to, string $ket): Npbg
    {
        return Npbg::create(['accurate_arinvoice_id' => $id, 'accurate_seq' => $seq, 'no_npbg' => $no, 'tgl_npbg' => '2025-10-01',
            'kode_barang' => "X.$id.$seq", 'deskripsi_barang' => $desc, 'kuantitas' => $qty, 'satuan' => 'PCS', 'pelanggan' => $to, 'keterangan' => $ket]);
    }

    public function test_two_tyres_on_two_lines_and_stpp_units_with_other_wording(): void
    {
        // two tyres, one per line, same product — the sync derived one row per line
        $l1 = $this->npbg(1, 'NA/25/X/108', 0, 'BAN LUAR - GITI / 7.50-R16 / GAR890', 1, 'W 8196 PK (HINO DUTRO)', 'PENGGANTIAN BAN');
        $l2 = $this->npbg(1, 'NA/25/X/108', 1, 'BAN LUAR - GITI / 7.50-R16 / GAR890', 1, 'W 8196 PK (HINO DUTRO)', 'PENGGANTIAN BAN');
        app(TyreFromAccurate::class)->derive();
        $this->assertSame(2, TyreChange::count());

        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Ban Luar');
        foreach (['5GA0130829 (M-0525)', '5GC6230911 (M-0525)'] as $i => $sn) {
            $ws->fromArray([null, 'W 8196 PK (HINO DUTRO)', 45930, 'NA/25/X/108', 'BAN LUAR STEEL - GITI / 7.50-R16 TT / GAR890', $sn, null, null, $i + 1, 1, 'GANTI'], null, 'B'.(6 + $i));
        }
        $path = tempnam(sys_get_temp_dir(), 'ban').'.xlsx';
        (new Xlsx($book))->save($path);
        $st = app(TyreExcelImporter::class)->import($path, null);
        $this->assertSame(2, $st['replaced_accurate']);
        $this->assertSame(2, TyreChange::count(), 'two tyres, not four');
        $this->assertEqualsCanonicalizing([$l1->id, $l2->id], TyreChange::pluck('out_npbg_id')->all(), 'one tyre per NPBG line');
        app(TyreFromAccurate::class)->derive();
        $this->assertSame(2, TyreChange::count(), 'the sync does not derive NA/25/X/108 again');
        app(TyreExcelImporter::class)->import($path, null);
        $this->assertSame(2, TyreChange::count(), 're-run');

        // STPP: the Excel writes the cables without "LOKAL"
        $c1 = $this->npbg(2, 'NA/25/XII/139', 0, 'KABEL MASSA LAS - LOKAL / P = 1m', 1, 'MAINTENANCE', 'UNTUK STPP');
        $c2 = $this->npbg(2, 'NA/25/XII/139', 1, 'KABEL STANG LAS - LOKAL / P = 1.5m', 1, 'MAINTENANCE', 'UNTUK STPP');
        $c3 = $this->npbg(2, 'NA/25/XII/139', 2, 'SEAL TAPE', 1, 'MAINTENANCE', 'UNTUK STPP');
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('STPP');
        $s->fromArray([1, 'SN-0318', 'KABEL STANG LAS - P = 1.5m', 1, 'PCS', 'A', 'GUDANG', 45990, 'NA/25/XII/139', 1], null, 'B6');
        $s->fromArray([2, 'SN-0319', 'KABEL MASSA LAS - P = 1m', 1, 'PCS', 'A', 'GUDANG', 45990, 'NA/25/XII/139', 2], null, 'B7');
        $path2 = tempnam(sys_get_temp_dir(), 'stpp').'.xlsx';
        (new Xlsx($book))->save($path2);
        app(StppExcelImporter::class)->import($path2, null);
        $this->assertSame($c2->id, StppTransaction::where('serial_no_raw', 'SN-0318')->value('out_npbg_id'));
        $this->assertSame($c1->id, StppTransaction::where('serial_no_raw', 'SN-0319')->value('out_npbg_id'));
        $this->assertSame(0, StppTransaction::where('out_npbg_id', $c3->id)->count(), 'the line the Excel does not list is not an STPP');

        @unlink($path);
        @unlink($path2);
    }
}
