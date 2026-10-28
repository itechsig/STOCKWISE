<?php

namespace Tests\Feature\Tracking;

use App\Models\BorrowTransaction;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ri;
use App\Services\Tracking\LendBorrowFromAccurate as L;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lend / Borrow recognised from real Accurate NPBG / RI keterangan (2026-09-29). */
class LendBorrowFromAccurateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_keyword_rules_on_real_keterangan(): void
    {
        // NPBG (goods out)
        $this->assertTrue(L::isLendOut('UNTUK DIPINJAMKAN KE PT. CDO'));
        $this->assertTrue(L::isLendOut('CDO PINJAM UNTUK PEMAKAIAN | ESTIMASI 30 HARI'));
        $this->assertTrue(L::isLendOut('UNTUK DIPINJAMKAN GUNA PENGIRIMAN TABUNG | NOTES: DIKEMBALIKAN KETIKA TABUNG DARI SIG-BPP'));
        $this->assertFalse(L::isLendOut('UNTUK MENGEMBALIKAN PINJAMAN DARI CDO'));
        $this->assertTrue(L::isBorrowReturn('UNTUK MENGEMBALIKAN PINJAMAN DARI CDO'));
        $this->assertTrue(L::isBorrowReturn('KEMBALIKAN PINJAMAN DARI CDO GUNA KEBUTUHAN CURAH PT. INFION YANG TIDAK JADI'));
        $this->assertFalse(L::isBorrowReturn('UNTUK DIPINJAMKAN KE PT. CDO'));
        // RI (goods in)
        $this->assertTrue(L::isBorrowIn('BARANG PINJAMAN DARI PT CIPTA ABADI UNTUK CONTOH CUSTOM KONEKSI'));
        $this->assertTrue(L::isBorrowIn('PINJAM TUTUP TABUNG DARI CDO U/ MELENGKAPI TABUNG PENJUALAN'));
        $this->assertTrue(L::isBorrowIn('PINJAM KE CDO U/ PERBAIKAN KEBOCORAN TANGKI LORRY TANK W 8683 QH'));
        $this->assertFalse(L::isBorrowIn('PENGEMBALIAN PINJAMAN DARI CDO UNTUK REF NPBG: NA/26/IV/268 (30.04.2026)'));
        $this->assertTrue(L::isLendReturn('PENGEMBALIAN PINJAMAN DARI CDO UNTUK REF NPBG: NA/26/IV/268 (30.04.2026)'));
        $this->assertTrue(L::isLendReturn('KEMBALI SETELAH DIPINJAMKAN U/ LEAK TES PROJECT PT. COMETA'));
        $this->assertTrue(L::isLendReturn('TIDAK JADI DIPINJAMKAN UNTUK KEPERLUAN CURAH KE PT. INFION'));
        $this->assertFalse(L::isLendReturn('BARANG PINJAMAN DARI PT CIPTA ABADI'));
    }

    private function npbg(int $id, string $no, string $date, string $code, float $qty, string $ket, string $to = 'PT. CANDI OXYGEN GASES'): Npbg
    {
        return Npbg::create(['accurate_arinvoice_id' => $id, 'accurate_seq' => 0, 'no_npbg' => $no, 'tgl_npbg' => $date,
            'kode_barang' => $code, 'deskripsi_barang' => $code, 'kuantitas' => $qty, 'satuan' => 'PCS', 'pelanggan' => $to, 'keterangan' => $ket]);
    }

    private function ri(int $id, string $no, string $date, string $code, float $qty, string $ket, string $from = 'PT. CANDI OXYGEN GASES'): Ri
    {
        return Ri::forceCreate(['accurate_apinvoice_id' => $id, 'accurate_seq' => 0, 'no_ri' => $no, 'tgl_ri' => $date, 'divisi' => 'NV',
            'kode_barang' => $code, 'deskripsi_barang' => $code, 'kuantitas' => $qty, 'satuan' => 'PCS', 'vendor' => $from, 'keterangan' => $ket]);
    }

    public function test_lend_and_borrow_are_built_and_returns_matched_idempotently(): void
    {
        foreach (['BSP.2722', 'SSP.2774', 'BSP.2691', 'SSP.2884'] as $code) {
            Item::factory()->create(['code' => $code]);
        }
        // lend out, then CDO returns part of it quoting the NPBG
        $this->npbg(1, 'NA/26/IV/268', '2026-04-30', 'BSP.2722', 4, 'CDO PINJAM UNTUK PEMAKAIAN | ESTIMASI 30 HARI');
        $this->npbg(2, 'NA/26/IV/268', '2026-04-30', 'SSP.2774', 8, 'CDO PINJAM UNTUK PEMAKAIAN | ESTIMASI 30 HARI');
        $this->ri(10, 'RI/NV/26/V/058', '2026-05-20', 'BSP.2722', 4, 'PENGEMBALIAN PINJAMAN DARI CDO UNTUK REF NPBG: NA/26/IV/268 (30.04.2026)');
        $this->ri(11, 'RI/NV/26/V/058', '2026-05-20', 'SSP.2774', 3, 'PENGEMBALIAN PINJAMAN DARI CDO UNTUK REF NPBG: NA/26/IV/268 (30.04.2026)');
        // borrow in from a vendor, returned by an NPBG quoting the RI
        $this->ri(20, 'RI/NV/26/VIII/053', '2026-08-20', 'SSP.2884', 1, 'BARANG PINJAMAN DARI PT CIPTA ABADI UNTUK CONTOH', 'PT. CIPTA ABADI');
        $this->npbg(21, 'NA/26/VIII/155', '2026-08-25', 'SSP.2884', 1, 'PENGEMBALIAN BARANG PINJAMAN DARI PT CIPTA ABADI. REF RI : RI/NV/26/VIII/053', 'PT. CIPTA ABADI');
        // noise: ordinary NPBG / RI
        $this->npbg(30, 'NA/26/IV/300', '2026-04-30', 'BSP.2691', 1, 'UNTUK STOK GUDANG');

        $first = app(L::class)->derive();
        $this->assertSame(3, $first['inserted']);

        $lendA = LendTransaction::where('item_id', Item::where('code', 'BSP.2722')->value('id'))->firstOrFail();
        $this->assertSame('RETURNED', $lendA->status);
        $this->assertSame('RI/NV/26/V/058', $lendA->return_refs);
        $this->assertSame('ACCURATE', $lendA->source);
        $this->assertSame('2026-05-30', $lendA->due_date->toDateString(), 'ESTIMASI 30 HARI');
        $lendB = LendTransaction::where('item_id', Item::where('code', 'SSP.2774')->value('id'))->firstOrFail();
        $this->assertSame('PARTIAL_RETURN', $lendB->status);
        $this->assertEquals(3, $lendB->qty_returned);

        $borrow = BorrowTransaction::firstOrFail();
        $this->assertSame('RETURNED', $borrow->status);
        $this->assertSame('PT. CIPTA ABADI', $borrow->lender_name);
        $this->assertSame('NA/26/VIII/155', $borrow->return_refs);

        // rerun: no duplicates, nothing reported as changed
        $again = app(L::class)->derive();
        $this->assertSame(['total' => 3, 'inserted' => 0, 'updated' => 0, 'skipped' => 3, 'errors' => 0], $again);
        $this->assertSame(2, LendTransaction::count());

        // the source NPBG line disappears from Accurate -> the loan goes too
        Npbg::where('accurate_arinvoice_id', 1)->delete();
        app(L::class)->derive();
        $this->assertSame(1, LendTransaction::count());
    }

    public function test_accurate_rows_are_read_only_in_stockwise(): void
    {
        Item::factory()->create(['code' => 'BSP.2722']);
        $this->npbg(1, 'NA/26/IV/268', '2026-04-30', 'BSP.2722', 4, 'UNTUK DIPINJAMKAN KE PT. CDO');
        app(L::class)->derive();
        $lend = LendTransaction::firstOrFail();

        $this->actingAsRole('admin_gudang');
        $this->getJson("/api/lend/{$lend->id}")->assertOk()
            ->assertJsonPath('data.source', 'ACCURATE')
            ->assertJsonPath('data.out_npbg', 'NA/26/IV/268');
        $this->postJson("/api/lend/{$lend->id}/return", ['qty' => 1])->assertStatus(422);
        $this->deleteJson("/api/lend/{$lend->id}")->assertStatus(422);
    }
}
