<?php

namespace Tests\Feature\Tracking;

use App\Models\BorrowTransaction;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ri;
use App\Models\TrackingPhoto;
use App\Services\Tracking\Import\LendBorrowExcelImporter;
use App\Services\Tracking\LendBorrowFromAccurate;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** "3. Tracking Borrow & Lend.xlsx" → Lend / Borrow, merged with the loans derived from Accurate. */
class LendBorrowExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_loans_take_over_accurate_ones_keep_pictures_and_follow_the_formula(): void
    {
        Storage::fake('local');
        Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/X/146', 'tgl_npbg' => '2025-10-01',
            'kode_barang' => 'B.1', 'deskripsi_barang' => 'BONIT BBB BR', 'kuantitas' => 4, 'satuan' => 'PCS', 'pelanggan' => 'CDO',
            'keterangan' => 'DIPINJAM UNTUK PEMAKAIAN CDO']);
        app(LendBorrowFromAccurate::class)->derive();
        $this->assertSame(1, LendTransaction::count());

        $book = new Spreadsheet;
        $lend = $book->getActiveSheet()->setTitle('Lend');
        $lend->fromArray([1, 45931, 'BONIT BBB BR', 4, 'PCS', 'RICCO', 'RELASI', 7, 'NA/25/X/146', 'DIPINJAM', null, 'RI/NV/26/I/062', 46036, 'KEMBALI LENGKAP'], null, 'B6');
        $lend->fromArray([2, 45931, 'LASHING RACHET', 2, 'PCS', 'RANDY', 'INTERNAL', 1, null, 'U/ KIRIM'], null, 'B7');
        $lend->setCellValueExplicit('J7', '=DISPIMG("ID_A1",1)', DataType::TYPE_STRING);
        $borrow = $book->createSheet()->setTitle('Borrow');
        $borrow->fromArray([1, 45801, 'BODY HARNESS SAFETY', 1, 'PCS', 'PT. CANDI OXYGEN GASES', 'PROJECT INTEGRA', 'RI/NV/25/V/040', null, 'NA/25/VIII/084'], null, 'B6');
        $path = tempnam(sys_get_temp_dir(), 'lb').'.xlsx';
        (new Xlsx($book))->save($path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/cellimages.xml', '<?xml version="1.0"?><etc:cellImages xmlns:etc="e" xmlns:xdr="x" xmlns:a="a" xmlns:r="r"><etc:cellImage><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="ID_A1"/></xdr:nvPicPr><xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill></xdr:pic></etc:cellImage></etc:cellImages>');
        $zip->addFromString('xl/_rels/cellimages.xml.rels', '<?xml version="1.0"?><Relationships><Relationship Id="rId1" Target="media/image1.jpeg"/></Relationships>');
        $zip->addFromString('xl/media/image1.jpeg', 'jpeg-bytes');
        $zip->close();

        $st = app(LendBorrowExcelImporter::class)->import($path, null);
        $this->assertSame([2, 1, 1, 1, 1], [$st['lend_rows'], $st['lend_replaced_accurate'], $st['borrow_rows'], $st['pictures'], $st['LUNAS']]);
        $this->assertSame(2, LendTransaction::count(), 'no duplicate of the Accurate loan');
        app(LendBorrowExcelImporter::class)->import($path, null);
        $this->assertSame(2, LendTransaction::count(), 're-run updates');

        $l1 = LendTransaction::where('sheet_no', 1)->sole();
        $this->assertSame(['EXCEL', 'RETURNED', 'RICCO', 'RI/NV/26/I/062'], [$l1->source, $l1->status, $l1->requester_name, $l1->return_doc_no]);
        $l2 = LendTransaction::where('sheet_no', 2)->sole();
        $this->assertSame('OVERDUE', $l2->status, 'Tanda Keluar picture + 1 day estimate, long past → DEADLINE');
        $this->assertSame(1, TrackingPhoto::where(['entity' => 'lend', 'entity_id' => $l2->id, 'slot' => 'out'])->count());
        $this->assertSame('RETURNED', BorrowTransaction::sole()->status);

        // the next Accurate sync keeps its hands off the Excel row
        app(LendBorrowFromAccurate::class)->derive();
        $this->assertSame(['EXCEL', 'RETURNED'], [$l1->fresh()->source, $l1->fresh()->status]);

        $this->actingAsRole('admin_gudang');
        $row = $this->getJson('/api/lend?search=LASHING')->assertOk()->json('data.0');
        $this->assertSame([2, 'OVERDUE'], [$row['no'], $row['status']]);
        $this->assertTrue(isset($row['photos']['out']));
        // edit an Excel row: every column, the status follows the formula
        $this->putJson("/api/lend/{$l2->id}", ['return_doc_no' => 'RI/NV/26/I/070', 'return_date' => '2026-01-20'])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED')->assertJsonPath('data.return_doc_no', 'RI/NV/26/I/070');
        $this->getJson('/api/borrow')->assertOk()->assertJsonPath('data.0.in_doc_no', 'RI/NV/25/V/040')->assertJsonPath('data.0.out_doc_no', 'NA/25/VIII/084');

        @unlink($path);
    }
}
