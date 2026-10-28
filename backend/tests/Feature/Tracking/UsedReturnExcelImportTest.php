<?php

namespace Tests\Feature\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use App\Services\Tracking\Import\UsedReturnExcelImporter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** "8. Tracking Pengembalian Bekas.xlsx" + DATA BUKTI → sheets Spare Part / Spare Part Lain. */
class UsedReturnExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';

    public function test_both_sheets_move_into_stockwise_with_their_photos(): void
    {
        Storage::fake('local');
        Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'ATK/25/IX/017', 'tgl_npbg' => '2025-09-26',
            'kode_barang' => 'L.1', 'deskripsi_barang' => 'LAMPU LED', 'kuantitas' => 2, 'satuan' => 'PCS', 'pelanggan' => 'GUDANG']);
        Ri::forceCreate(['accurate_apinvoice_id' => 1, 'accurate_seq' => 0, 'no_ri' => 'RI/NV/25/IX/100', 'tgl_ri' => '2025-09-29', 'divisi' => 'NV',
            'kode_barang' => 'L.2', 'deskripsi_barang' => '(BUANG) LAMPU LED', 'kuantitas' => 2, 'satuan' => 'PCS', 'vendor' => 'GUDANG']);

        $book = new Spreadsheet;
        $sp = $book->getActiveSheet()->setTitle('Spare Part');
        $sp->fromArray([1, 45933, 'ATK/25/IX/017', null, 45936, 'RI/NV/25/IX/100', null, null, null, 2, null, null, -4, null, null, null, null, -2, null, null, '1. Pengembalian Valve'], null, 'B7');
        $sp->fromArray([2, 45934, 'ATK/25/IX/017'], null, 'B8');
        $sl = $book->createSheet()->setTitle('Spare Part Lain');
        $sl->fromArray([null, 45912, 'ATK/25/IX/017', '(BUANG) LAMPU LED', 2, 'PCS', 11, 'ATK.25.IX.017_11', null, null,
            'RI/NV/25/IX/100', 11, 'RI.NV.25.IX.100_11', null, 'BARTER BEKAS'], null, 'B6');
        $sl->fromArray([null, 45913, 'ATK/25/IX/017', 'KABEL', 1, 'ROL', 12, 'ATK.25.IX.017_12'], null, 'B7');
        $path = tempnam(sys_get_temp_dir(), 'ur').'.xlsx';
        (new Xlsx($book))->save($path);

        $dir = sys_get_temp_dir().'/ur-'.uniqid();
        mkdir("{$dir}/Foto Keluar", 0777, true);
        mkdir("{$dir}/Foto Terima", 0777, true);
        file_put_contents("{$dir}/Foto Keluar/ATK.25.IX.017_11.JPG", 'out');
        file_put_contents("{$dir}/Foto Terima/RI.NV.25.IX.100_11.jpg", 'in');

        $st = app(UsedReturnExcelImporter::class)->import($path, $dir);
        $this->assertSame([2, 2, 1, 1, 2, 2], [$st['spare_part'], $st['spare_part_lain'], $st['photo_out'], $st['photo_in'], $st['CLEAR'], $st['PENDING']]);
        app(UsedReturnExcelImporter::class)->import($path, $dir);
        $this->assertSame(2, DB::table('used_return_matrix_rows')->count(), 're-run updates');

        $this->actingAsRole('admin_gudang');
        $m = $this->getJson('/api/used-return-sheets/matrix')->assertOk();
        $this->assertSame(['VALVE_BR' => 2, 'MUR_CS' => -4, 'BAUT_CS' => -2], $m->json('data.1.components'));
        $this->assertEquals(-4, $m->json('meta.totals.MUR_CS'));
        $this->assertSame(['CLEAR', true, true], [$m->json('data.1.status'), $m->json('data.1.npbg_linked'), $m->json('data.1.ri_linked')]);

        $o = $this->getJson('/api/used-return-sheets/other')->assertOk()->json('data');
        $this->assertSame(['PENDING', 'CLEAR'], array_column($o, 'status'));
        $this->assertTrue(isset($o[1]['photos']['out'], $o[1]['photos']['in']));
        $this->assertSame('ATK.25.IX.017_12', $o[0]['helper_out']);

        // pending row: RI + Foto Terima from the app → CLEAR (the Excel formula)
        $id = $o[0]['id'];
        $this->putJson("/api/used-return-sheets/other/{$id}", ['ri_no' => 'RI/NV/25/IX/100', 'return_item_no' => 12])->assertOk()->assertJsonPath('data.status', 'PENDING');
        $this->postJson('/api/tracking-photos', ['entity' => 'used_return_line', 'entity_id' => $id, 'slot' => 'in', 'photo' => self::PNG])->assertCreated();
        $this->getJson("/api/used-return-sheets/other/{$id}")->assertJsonPath('data.status', 'CLEAR')->assertJsonPath('data.ri.number', 'RI/NV/25/IX/100');
        $this->postJson('/api/used-return-sheets/matrix', ['npbg_no' => 'ATK/25/IX/017', 'components' => ['PEN_SS' => 3]])->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.no', 3);
        $this->putJson("/api/used-return-sheets/other/{$id}", ['npbg_no' => 'NA/99/I/999'])->assertStatus(422);

        @unlink($path);
        array_map('unlink', glob("{$dir}/*/*"));
    }
}
