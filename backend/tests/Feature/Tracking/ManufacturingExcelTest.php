<?php

namespace Tests\Feature\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\Site;
use App\Support\ManufacturingExcel as X;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Manufaktur & Assembly = the Excel "7. Tracking Manufaktur & Assembly", sheets MA and MJ. */
class ManufacturingExcelTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    /** Accurate NPBG / RI mirror rows the typed numbers must exist in. */
    private function accurateDocs(): void
    {
        foreach (['NA/25/VII/251', 'NA/25/VII/280', 'NA/25/VII/300', 'NA/25/VIII/258'] as $i => $no) {
            Npbg::create(['accurate_arinvoice_id' => $i + 1, 'accurate_seq' => 0, 'no_npbg' => $no, 'tgl_npbg' => '2025-07-24',
                'kode_barang' => 'X.1', 'deskripsi_barang' => 'PLAT CS', 'kuantitas' => 1, 'satuan' => 'PCS', 'pelanggan' => 'SIG']);
        }
        Ri::forceCreate(['accurate_apinvoice_id' => 1, 'accurate_seq' => 0, 'no_ri' => 'RI/NV/25/VIII/019', 'tgl_ri' => '2025-08-05', 'divisi' => 'NV',
            'kode_barang' => 'X.2', 'deskripsi_barang' => 'CRADLE CARBON STEEL 3x2', 'kuantitas' => 2, 'satuan' => 'PCS', 'vendor' => 'SIG']);
    }

    public function test_status_and_file_name_formulas(): void
    {
        // =IF(AND(foto,tgl,RI),"COMPLETED",IF(NPBG,"ON-GOING",IF(AND(hasil,seri,proses),"REQUESTED","")))
        $this->assertSame(X::COMPLETED, X::status('f.jpg', '2025-08-05', 'RI/NV/25/VIII/019', 'NA/25/VII/251', 'CRADLE', 'SIG-58', 'CRA(1)'));
        $this->assertSame(X::ON_GOING, X::status(null, '2025-08-05', 'RI/NV/25/VIII/019', 'NA/25/VII/251', 'CRADLE', 'SIG-58', 'CRA(1)'), 'no photo yet');
        $this->assertSame(X::ON_GOING, X::status(null, null, null, 'NA/25/VII/251', null, null, null));
        $this->assertSame(X::REQUESTED, X::status(null, null, null, null, 'CRADLE', 'SIG-58', 'CRA(1)'));
        $this->assertSame(X::REQUESTED, X::status(null, null, null, null, 'PIPA', '-', 'JASA PEMOTONGAN'), '"-" counts as filled');
        $this->assertSame(X::INCOMPLETE, X::status(null, null, null, null, 'CRADLE', null, 'CRA(1)'));

        $this->assertSame('CRADLE CARBON STEEL 3x2 [SIG-58]_1', X::fileName('ASSEMBLY', 'MA/25/VII/001', 'SUB-01', 'CRADLE CARBON STEEL 3x2', 'SIG-58', 1));
        $this->assertSame('MJ.25.VIII.021_SUB-01_1', X::fileName('JASA', 'MJ/25/VIII/021', 'SUB-01', 'MANIFOLD', '-', 1));

        $this->assertSame(X::REQUESTED, X::orderStatus([]));
        $this->assertSame(X::ON_GOING, X::orderStatus([X::COMPLETED, X::REQUESTED]));
        $this->assertSame(X::COMPLETED, X::orderStatus([X::COMPLETED, X::COMPLETED]));
    }

    public function test_ma_rows_follow_the_sheet(): void
    {
        Storage::fake('local');
        Site::factory()->create();
        $this->accurateDocs();
        $this->actingAsRole('admin_gudang');

        $mo = $this->postJson('/api/manufacturing-orders', [
            'kind' => 'ASSEMBLY', 'date' => '2025-07-25', 'product_name' => 'CRADLE CARBON STEEL 3x2',
            'lines' => [
                ['serial_no_raw' => 'SIG-58', 'process' => 'CRA(1) - PEMBUATAN RANGKA', 'note_start' => 'UNTUK FABRIKASI'],
                ['serial_no_raw' => 'SIG-59', 'process' => 'CRA(1) - PEMBUATAN RANGKA', 'npbg_no' => 'na/25/vii/251'],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'ON_GOING')->assertJsonPath('data.location', 'SIG')->json('data');
        $this->assertMatchesRegularExpression('#^MA/25/VII/\d{3}$#', $mo['number']);

        $subs = $this->getJson("/api/manufacturing-orders/{$mo['id']}")->assertOk()->json('data.subs');
        $this->assertSame(['SUB-01', 'SUB-01'], array_column($subs, 'sub_no'));
        $this->assertSame([1, 2], array_column($subs, 'item_no'), 'Item No runs over the sheet');
        $this->assertSame(['REQUESTED', 'ON_GOING'], array_column($subs, 'status'));
        $this->assertSame('CRADLE CARBON STEEL 3x2 [SIG-58]_1', $subs[0]['helper_file_name']);
        $this->assertNull($subs[0]['file_name'], 'Nama File is only set once the photo exists');
        $this->assertSame([1, 2], array_column($subs, 'no'), 'Excel No');
        $this->assertSame('NA/25/VII/251', $subs[1]['npbg_no']);

        // next NPBG batch for the same unit = SUB-02
        $this->postJson("/api/manufacturing-orders/{$mo['id']}/subs", [
            'serial_no_raw' => 'SIG-58', 'process' => 'CRA(2) - PEMASANGAN KOMPONEN', 'npbg_no' => 'NA/25/VII/280', 'new_sub' => true,
        ])->assertOk()->assertJsonPath('data.subs.2.sub_no', 'SUB-02')->assertJsonPath('data.subs.2.item_no', 3);

        // Selesai without a photo: still ON-GOING, exactly like the Excel
        $this->postJson("/api/manufacturing-subs/{$subs[1]['id']}/complete", ['finish_date' => '2025-08-05', 'ri_no' => 'RI/NV/25/VIII/019'])
            ->assertOk()->assertJsonPath('data.subs.1.status', 'ON_GOING');
        $this->postJson("/api/manufacturing-subs/{$subs[1]['id']}/photo", ['photo' => self::PNG])
            ->assertOk()->assertJsonPath('data.subs.1.status', 'COMPLETED')->assertJsonPath('data.subs.1.has_photo', true);
        $this->get("/api/manufacturing-subs/{$subs[1]['id']}/photo")->assertOk();
        $this->postJson("/api/manufacturing-subs/{$subs[1]['id']}/photo", ['photo' => 'data:text/plain;base64,aGk='])->assertStatus(422);

        // a COMPLETED row cannot be deleted; an open one can
        $this->deleteJson("/api/manufacturing-subs/{$subs[1]['id']}")->assertStatus(422);

        // sheet view: one row per unit, only this sheet
        $this->postJson('/api/manufacturing-orders', ['kind' => 'JASA', 'location' => 'PT. GLOBAL HOSINDO',
            'lines' => [['product_name' => 'FLEXIBLE HOSE', 'serial_no_raw' => '-', 'process' => 'JASA PERBAIKAN']]])->assertCreated();
        $lines = $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY')->assertOk()->assertJsonPath('meta.total', 3)->json('data');
        $this->assertSame($mo['id'], $lines[0]['id']);
        $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY&status=completed')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.file_name', 'CRADLE CARBON STEEL 3x2 [SIG-59]_2')->assertJsonPath('data.0.npbg_linked', true);
        $this->getJson('/api/manufacturing-lines?kind=JASA')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_no', 1)
            ->assertJsonPath('data.0.status', 'REQUESTED')
            ->assertJsonPath('data.0.helper_file_name', fn ($f) => (bool) preg_match('#^MJ\.\d{2}\.[IVX]+\.\d{3}_SUB-01_1$#', $f));
        $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY&search=SIG-58')->assertJsonPath('meta.total', 2);

        // every change is editable per Excel column; status recomputed
        $this->putJson("/api/manufacturing-subs/{$subs[0]['id']}", ['npbg_no' => 'NA/25/VII/300'])
            ->assertOk()->assertJsonPath('data.subs.0.status', 'ON_GOING');
    }

    public function test_pick_lists_serials_excel_no_and_next_number(): void
    {
        $this->assertSame(['SIG-033', 'SIG-034', 'SIG-035'], X::serials("UNTUK FABRIKASI CRADLE VGL\r\nNO SERI: SIG-033 s.d SIG-035"));
        $this->assertSame(['SIG-58', 'SIG-59'], X::serials('CRADLE 3X2 NO. SIG-58 DAN SIG-59 (STEP 1)'));
        $this->assertSame(['SIG-109', 'SIG-012'], X::serials('NO SERI: SIG-109, SIG-012'));
        $this->assertSame([], X::serials('UNTUK STOK GUDANG'));

        Site::factory()->create();
        $this->accurateDocs();
        Npbg::create(['accurate_arinvoice_id' => 99, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/X/004', 'tgl_npbg' => '2025-10-01',
            'kode_barang' => 'X.9', 'deskripsi_barang' => 'SIKU CS', 'kuantitas' => 4, 'satuan' => 'PCS', 'pelanggan' => 'MAINTENANCE (SDA)',
            'keterangan' => 'UNTUK PEMASANGAN KOMPONEN CRADLE VGL NO SERI: SIG-008 s.d SIG-010']);
        $this->actingAsRole('admin_gudang');

        // pick list: by number (spaces ignored) or by words of the keterangan, newest first
        $this->getJson('/api/tracking/documents?type=npbg&q=na/25/x')->assertOk()->assertJsonPath('data.0.number', 'NA/25/X/004');
        $this->getJson('/api/tracking/documents?type=npbg&q=komponen vgl')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serials', ['SIG-008', 'SIG-009', 'SIG-010']);
        $this->getJson('/api/tracking/documents?type=ri')->assertOk()->assertJsonPath('data.0.number', 'RI/NV/25/VIII/019');

        $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY')->assertOk()
            ->assertJsonPath('meta.next_number', fn ($n) => (bool) preg_match('#^MA/\d{2}/[IVX]+/001$#', $n));
        $this->postJson('/api/manufacturing-orders', ['kind' => 'ASSEMBLY', 'date' => '2025-07-25', 'product_name' => 'CRADLE VGL',
            'lines' => [['serial_no_raw' => 'SIG-008', 'process' => 'CRA(1)'], ['serial_no_raw' => 'SIG-009', 'process' => 'CRA(1)']]])->assertCreated();
        $this->postJson('/api/manufacturing-orders', ['kind' => 'ASSEMBLY', 'date' => '2025-07-20', 'product_name' => 'CRADLE VGL',
            'lines' => [['serial_no_raw' => 'SIG-010', 'process' => 'CRA(1)']]])->assertCreated();
        // Excel "No" = next row of the sheet, newest shown first, kept when filtering
        $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY')->assertJsonPath('data.0.no', 3)->assertJsonPath('data.0.serial_no', 'SIG-010')
            ->assertJsonPath('data.2.no', 1)->assertJsonPath('data.0.date', '2025-07-20');
        $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY&search=SIG-009')->assertJsonPath('data.0.no', 2);
    }

    public function test_excel_rows_are_imported_as_typed_and_linked(): void
    {
        Site::factory()->create();
        $this->accurateDocs();
        \App\Models\Item::factory()->create(['description' => 'PIPA TEMBAGA 8mm x 2mm x 96cm']);
        \App\Models\Vendor::factory()->create(['name' => 'CV. NURUL JAYA TEHNIK']);

        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $ma = $book->getActiveSheet()->setTitle('Manufaktur & Assembly');
        $ma->fromArray([1, 45864, 'MA/25/VII/001', 'SUB-01', 'SIG', 'CRADLE CARBON STEEL 3x2', 'SIG-58', 1, 'CRA(1) - PEMBUATAN RANGKA',
            'UNTUK FABRIKASI', null, 'NA/25/VII/251', 'CRADLE CARBON STEEL 3x2 [SIG-58]_1', null, 45875, 'RI/NV/25/VIII/019', 'SELESAI'], null, 'B6');
        $ma->fromArray([2, 45864, 'MA/25/VII/001', 'SUB-01', 'SIG', 'CRADLE CARBON STEEL 3x2', 'SIG-59', 2, 'CRA(1) - PEMBUATAN RANGKA',
            'UNTUK FABRIKASI', null, 'NA/24/I/001'], null, 'B7');
        $mj = $book->createSheet()->setTitle('Manufaktur & Jasa Lain-Lain');
        $mj->fromArray([1, 45897, 'MJ/25/VIII/023', 'SUB-01', 1, 'CV. NURUL JAYA TEHNIK', 'PIPA TEMBAGA 8mm x 2mm x 96cm', '-', 'JASA PEMOTONGAN',
            'UNTUK DIPOTONG', null, 'NA/25/VIII/258'], null, 'B6');
        $path = tempnam(sys_get_temp_dir(), 'mfg').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $importer = app(\App\Services\Tracking\ManufacturingExcelImporter::class);
        $this->assertSame(0, $importer->import($path, true)['MA']['orders'] - 1, 'dry run counts');
        $this->assertDatabaseCount('manufacturing_order_subs', 0);

        $stats = $importer->import($path);
        $this->assertEquals(['rows' => 2, 'created' => 2, 'COMPLETED' => 1, 'ON_GOING' => 1, 'npbg_linked' => 1, 'ri_linked' => 1],
            array_intersect_key($stats['MA'], array_flip(['rows', 'created', 'COMPLETED', 'ON_GOING', 'npbg_linked', 'ri_linked'])));
        $this->assertSame(1, $stats['MJ']['item_linked']);
        $this->assertSame(0, $importer->import($path)['MA']['created'], 're-run updates, never duplicates');
        $this->assertDatabaseCount('manufacturing_order_subs', 3);

        $this->actingAsRole('admin_gudang');
        $ma = $this->getJson('/api/manufacturing-lines?kind=ASSEMBLY')->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($ma, 'no'));
        $this->assertSame(['ON_GOING', 'COMPLETED'], array_column($ma, 'status'));
        $this->assertSame([false, true], array_column($ma, 'npbg_linked'), 'NA/24/I/001 is not in the Accurate data — stays text');
        $this->assertSame('CRADLE CARBON STEEL 3x2 [SIG-58]_1', $ma[1]['file_name']);
        $this->assertSame('2025-08-06', $ma[1]['finish_date']); // Excel serial 45875
        $mjRow = $this->getJson('/api/manufacturing-lines?kind=JASA')->json('data.0');
        $this->assertNotNull($mjRow['item_code'], 'Hasil Produk linked to the Accurate item');
        $this->assertSame('CV. NURUL JAYA TEHNIK', $mjRow['location']);
        $this->assertSame('MA/25/VII/002', app(\App\Services\DocumentNumberService::class)->nextShort('MA', \Illuminate\Support\Carbon::parse('2025-07-30')),
            'numbering continues after the imported numbers');

        // input choices: the sheet's used values + the database (Accurate item, vendor)
        $this->getJson('/api/manufacturing-lines/options?kind=JASA')->assertOk()
            ->assertJsonPath('data.processes', ['JASA PEMOTONGAN'])->assertJsonPath('data.locations', ['CV. NURUL JAYA TEHNIK']);
        $this->assertContains('PIPA TEMBAGA 8mm x 2mm x 96cm', $this->getJson('/api/manufacturing-lines/options?kind=JASA&field=product&q=tembaga')->json('data'));
        $this->assertContains('CV. NURUL JAYA TEHNIK', $this->getJson('/api/manufacturing-lines/options?kind=ASSEMBLY&field=location&q=nurul')->json('data'));

        // Foto Hasil from the DATA BUKTI folder, found by Nama File (the sheet's own folder wins)
        \Illuminate\Support\Facades\Storage::fake('local');
        $dir = sys_get_temp_dir().'/mfg-photos-'.uniqid();
        mkdir("{$dir}/MA CRADLE", 0777, true);
        mkdir("{$dir}/Manufaktur & Assembly", 0777, true);
        file_put_contents("{$dir}/MA CRADLE/CRADLE CARBON STEEL 3x2 [SIG-58]_1.jpg", 'other');
        file_put_contents("{$dir}/Manufaktur & Assembly/CRADLE CARBON STEEL 3x2 [SIG-58]_1.jpg", 'sheet');
        $p = $importer->linkPhotos($dir);
        $this->assertSame(['rows_with_name' => 1, 'linked' => 1, 'already' => 0], array_intersect_key($p, array_flip(['rows_with_name', 'linked', 'already'])));
        $sub = \App\Models\ManufacturingOrderSub::whereNotNull('photo_path')->sole();
        $this->assertSame('sheet', \Illuminate\Support\Facades\Storage::disk('local')->get($sub->photo_path));
        $this->get("/api/manufacturing-subs/{$sub->id}/photo")->assertOk();
        $this->assertSame(1, $importer->linkPhotos($dir)['already'], 'second run keeps it');
        array_map('unlink', glob("{$dir}/*/*.jpg"));
        @unlink($path);
    }

    public function test_export_has_both_sheets_in_excel_column_order(): void
    {
        Site::factory()->create();
        $this->accurateDocs();
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/manufacturing-orders', ['kind' => 'JASA', 'location' => 'SIG',
            'lines' => [['product_name' => 'PIPA TEMBAGA', 'serial_no_raw' => '-', 'process' => 'JASA PEMOTONGAN', 'npbg_no' => 'NA/25/VIII/258']]])->assertCreated();

        $book = app(\App\Services\Export\Legacy\LegacyExcelExportService::class)->manufakturAssembly();
        $this->assertSame(['Manufaktur & Assembly', 'Manufaktur & Jasa Lain-Lain'], $book->getSheetNames());
        $mj = $book->getSheetByName('Manufaktur & Jasa Lain-Lain');
        $this->assertSame(['No', 'Tanggal', 'No. Manufaktur & Jasa', 'Sub MJ', 'Item No', 'Lokasi', 'Hasil Produk'],
            array_slice($mj->rangeToArray('A1:G1')[0], 0, 7));
        $this->assertSame('ON-GOING', $mj->getCell('K2')->getValue());
        $this->assertSame('PIPA TEMBAGA', $mj->getCell('G2')->getValue());
    }
}
