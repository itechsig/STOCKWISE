<?php

namespace Tests\Feature\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\TrackingPhoto;
use App\Models\TyreChange;
use App\Services\Tracking\Import\TyreExcelImporter;
use App\Services\Tracking\TyreFromAccurate;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** "5. Tracking Ban Luar.xlsx" + DATA BUKTI → Ban Luar, merged with the tyres made from Accurate. */
class TyreExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    public function test_excel_tyres_take_over_accurate_ones_and_the_sync_leaves_them(): void
    {
        Storage::fake('local');
        Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/IX/184', 'tgl_npbg' => '2025-09-25',
            'kode_barang' => 'B.1', 'deskripsi_barang' => 'BAN LUAR NYLON - GT / 7.50-16', 'kuantitas' => 1, 'satuan' => 'PCS',
            'pelanggan' => 'W 8196 PK (HINO DUTRO)', 'keterangan' => 'PENGGANTIAN BAN DEPAN KANAN']);
        Ri::forceCreate(['accurate_apinvoice_id' => 1, 'accurate_seq' => 0, 'no_ri' => 'RI/NV/25/X/003', 'tgl_ri' => '2025-10-02', 'divisi' => 'NV',
            'kode_barang' => 'B.2', 'deskripsi_barang' => '(BEKAS) BAN LUAR NYLON', 'kuantitas' => 1, 'satuan' => 'PCS', 'vendor' => 'W 8196 PK (HINO DUTRO)',
            'keterangan' => 'PENGEMBALIAN BAN LUAR BEKAS REF NPBG: NA/25/IX/184']);
        app(TyreFromAccurate::class)->derive();
        $this->assertSame(1, TyreChange::count());

        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Ban Luar');
        $ws->fromArray([null, 'W 8196 PK (HINO DUTRO)', 45925, 'NA/25/IX/184', 'BAN LUAR NYLON - GT / 7.50-16', '6323 (M-1224)', 'NA.25.IX.184 6323 (M-1224)', null,
            1, 1, 'PENGGANTIAN BAN', null, 45932, 'RI/NV/25/X/003', '(BEKAS) BAN LUAR NYLON', '7586 (M-2820)', 'RI.NV.25.X.003 7586 (M-2820)', null, 'BEKAS KEMBALI'], null, 'B6');
        $ws->fromArray([null, 'W 8246 NZ (NISSAN CWB)', 45683, 'ORIGIN', 'BAN LUAR STEEL - ROVELO', '2320611829 (M-0523)', null, null, 1, 1,
            'PENDATAAN AWAL', null, '-', 'ORIGIN', '-', '-'], null, 'B7');
        $bpn = $book->createSheet()->setTitle('Ban Luar BPN');
        $bpn->fromArray([1, 45952, 'W 8196 PK (HINO DUTRO)', 'BAN LUAR NYLON - GT', '6120 (M-2624)', null, 'PENDATAAN AWAL'], null, 'A7');
        $bpn->setCellValueExplicit('F7', '=DISPIMG("ID_B0A1",1)', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $book->createSheet()->setTitle('Deliver & Receive Ban SIG-BPN')
            ->fromArray([1, 'W 8044 PV (HINO DUTRO)', 45948, 'NA/25/IX/184', '(VULKANISIR) BAN LUAR', '6121 (M-2624)', null, 'KIRIM KE BPN'], null, 'A7');
        $path = tempnam(sys_get_temp_dir(), 'ban').'.xlsx';
        (new Xlsx($book))->save($path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/cellimages.xml', '<?xml version="1.0"?><etc:cellImages xmlns:etc="e" xmlns:xdr="x" xmlns:a="a" xmlns:r="r"><etc:cellImage><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="ID_B0A1"/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic></etc:cellImage></etc:cellImages>');
        $zip->addFromString('xl/_rels/cellimages.xml.rels', '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="media/image1.png"/></Relationships>');
        $zip->addFromString('xl/media/image1.png', base64_decode(self::PNG));
        $zip->close();

        $dir = sys_get_temp_dir().'/ban-'.uniqid();
        mkdir("{$dir}/NPBG Ban", 0777, true);
        mkdir("{$dir}/RI Ban", 0777, true);
        file_put_contents("{$dir}/NPBG Ban/NA.25.IX.184 6323 (M-1224).jpg", 'out');
        file_put_contents("{$dir}/RI Ban/RI.NV.25.X.003 7586 (M-2820).jpg", 'in');

        $st = app(TyreExcelImporter::class)->import($path, $dir);
        $this->assertSame([2, 1, 1, 1, 1, 1, 1, 1], [$st['rows'], $st['opening'], $st['replaced_accurate'], $st['photo_out'], $st['photo_in'],
            $st['bpn_inventory'], $st['bpn_transfers'], $st['bpn_photos']]);
        $this->assertSame(2, TyreChange::count(), 'no duplicate of the Accurate tyre');
        $this->assertSame(0, app(TyreExcelImporter::class)->import($path, $dir)['created'], 're-run updates');

        $t = TyreChange::where('out_npbg_no', 'NA/25/IX/184')->sole();
        $this->assertSame(['EXCEL', 'CLEAR', '6323 (M-1224)', 1, 1], [$t->source, $t->status, $t->new_serial_raw, $t->tyre_no, $t->replacement_no]);
        $this->assertTrue((bool) TyreChange::where('out_npbg_no', 'ORIGIN')->value('is_opening'));

        // the next Accurate sync keeps its hands off the Excel row
        app(TyreFromAccurate::class)->derive();
        $this->assertSame(2, TyreChange::count());
        $this->assertSame(['EXCEL', 'BEKAS KEMBALI'], [$t->fresh()->source, $t->fresh()->in_note]);

        $this->actingAsRole('admin_gudang');
        $row = $this->getJson('/api/tyre-changes?search=6323')->assertOk()->json('data.0');
        $this->assertSame([true, true, 'NA.25.IX.184 6323 (M-1224)'], [$row['npbg_linked'], $row['ri_linked'], $row['helper_out']]);
        $this->assertTrue(isset($row['photos']['out'], $row['photos']['in']));
        $this->getJson('/api/tyre-bpn/inventory')->assertOk()->assertJsonPath('data.0.serial_raw', '6120 (M-2624)')
            ->assertJsonPath('data.0.photos.photo.id', TrackingPhoto::where('entity', 'tyre_bpn')->value('id'));
        $this->getJson('/api/tyre-bpn/transfers')->assertOk()->assertJsonPath('data.0.npbg_id', $t->out_npbg_id);
        $this->getJson('/api/tyre-changes/options')->assertOk()->assertJsonPath('data.nopols', fn ($n) => in_array('W 8196 PK (HINO DUTRO)', $n, true));

        @unlink($path);
        array_map('unlink', glob("{$dir}/*/*"));
    }
}
