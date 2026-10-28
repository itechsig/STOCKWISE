<?php

namespace Tests\Feature\Tracking;

use App\Models\MaintenanceOrderSub;
use App\Models\Npbg;
use App\Models\Site;
use App\Models\TrackingPhoto;
use App\Services\Tracking\Import\MaintenanceExcelImporter;
use App\Support\WpsCellImages;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** "6. Tracking Maintenance Assets.xlsx" + DATA BUKTI → Maintenance, photos included. */
class MaintenanceExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    public function test_rows_pictures_and_photos_move_into_stockwise(): void
    {
        Storage::fake('local');
        Site::factory()->create();
        Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/X/009', 'tgl_npbg' => '2025-10-01',
            'kode_barang' => 'X.1', 'deskripsi_barang' => 'CAT', 'kuantitas' => 1, 'satuan' => 'KLG', 'pelanggan' => 'W 8747 PD (HINO DUTRO)']);

        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Maintenance Kendaraan');
        $ws->fromArray([null, 45931, 'MK/25/X/001', 'SUB-01', 'W 8747 PD (HINO DUTRO)', 'REKONDISI BAK', 'MK.25.X.001_SUB-01_Before', '.jpg', null, 'SIG',
            null, null, 'NA/25/X/009', 'MK.25.X.001_SUB-01_After', '.jpg', null, 45933, 'BAK SUDAH BAGUS'], null, 'B6');
        $ws->fromArray([null, 45932, 'MK/25/X/001', 'SUB-02', 'W 8747 PD (HINO DUTRO)', 'CAT ULANG', null, null, null, 'SIG',
            null, null, null], null, 'B7');
        $ws->setCellValueExplicit('L6', '=DISPIMG("ID_AAA111",1)', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('L7', '-');
        $path = tempnam(sys_get_temp_dir(), 'mk').'.xlsx';
        (new Xlsx($book))->save($path);
        // the picture inside cell L6, the way WPS stores it
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/cellimages.xml', '<?xml version="1.0"?><etc:cellImages xmlns:etc="http://www.wps.cn/officeDocument/2017/etCustomData" xmlns:xdr="x" xmlns:a="a" xmlns:r="r"><etc:cellImage><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="ID_AAA111"/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic></etc:cellImage></etc:cellImages>');
        $zip->addFromString('xl/_rels/cellimages.xml.rels', '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Type="image" Target="media/image1.png"/></Relationships>');
        $zip->addFromString('xl/media/image1.png', base64_decode(self::PNG));
        $zip->close();
        $this->assertSame(1, (new WpsCellImages($path))->count());
        $this->assertSame('ID_AAA111', WpsCellImages::idOf('=DISPIMG("ID_AAA111",1)'));

        $dir = sys_get_temp_dir().'/mk-photos-'.uniqid();
        mkdir("{$dir}/Laporan kerusakan", 0777, true);
        mkdir("{$dir}/Selesai pengerjaan", 0777, true);
        file_put_contents("{$dir}/Laporan kerusakan/MK.25.X.001_SUB-01_Before.jpg", 'before');
        file_put_contents("{$dir}/Selesai pengerjaan/MK.25.X.001_SUB-01_After.mp4", 'video');

        $importer = app(MaintenanceExcelImporter::class);
        $st = $importer->import($path, $dir);
        $this->assertSame([2, 1, 1, 1, 1, 1], [$st['rows'], $st['orders'], $st['npbg_linked'], $st['photo_request'], $st['photo_before'], $st['photo_after']]);
        $this->assertSame(0, $importer->import($path, $dir)['created'], 're-run updates');
        $this->assertSame(2, MaintenanceOrderSub::count());
        $this->assertSame(3, TrackingPhoto::count());
        $this->assertSame('video/mp4', TrackingPhoto::where('slot', 'after')->value('mime'));

        [$s1, $s2] = MaintenanceOrderSub::orderBy('sheet_no')->get();
        $this->assertSame('COMPLETED', $s1->status, 'NPBG + Nama File 2 + Tgl Selesai (formula; the file has no cached value)');
        $this->assertSame('REQUESTED', $s2->status, 'Permintaan "-" counts as filled');
        $this->assertSame('-', $s2->request_note);
        $this->assertSame('MK/25/XI/001', app(\App\Services\DocumentNumberService::class)->peekShort('MK', \Illuminate\Support\Carbon::parse('2025-11-02')));
        $this->assertSame('MK/25/X/002', app(\App\Services\DocumentNumberService::class)->peekShort('MK', \Illuminate\Support\Carbon::parse('2025-10-20')));

        $this->actingAsRole('admin_gudang');
        $line = $this->getJson('/api/maintenance-lines')->assertOk()->json('data.1');
        $this->assertSame(1, $line['no']);
        $this->assertTrue(isset($line['photos']['request'], $line['photos']['before'], $line['photos']['after']), 'Permintaan, Foto Sebelum & Sesudah');
        $this->assertTrue($line['npbg_linked']);
        $this->getJson('/api/maintenance-lines/options')->assertJsonPath('data.nopols.0', 'W 8747 PD (HINO DUTRO)');

        @unlink($path);
        array_map('unlink', glob("{$dir}/*/*"));
    }
}
