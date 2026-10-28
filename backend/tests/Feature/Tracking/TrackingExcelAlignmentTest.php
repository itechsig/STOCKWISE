<?php

namespace Tests\Feature\Tracking;

use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ri;
use App\Models\Site;
use App\Models\TyreChange;
use App\Services\Tracking\LendBorrowFromAccurate;
use App\Services\Tracking\TyreFromAccurate;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tracking modules follow the reference Excel files' columns (format only —
 * never a data source): data comes from Accurate NPBG/RI where it exists,
 * the rest is typed in STOCKWISE. Keterangan below are real Accurate texts.
 */
class TrackingExcelAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function npbg(int $id, string $no, string $date, string $desc, float $qty, string $ket, string $to, ?string $peminta = null, string $code = 'X.1'): Npbg
    {
        return Npbg::create(['accurate_arinvoice_id' => $id, 'accurate_seq' => 0, 'no_npbg' => $no, 'tgl_npbg' => $date,
            'kode_barang' => $code, 'deskripsi_barang' => $desc, 'kuantitas' => $qty, 'satuan' => 'PCS',
            'pelanggan' => $to, 'keterangan' => $ket, 'peminta' => $peminta, 'divisi' => 'MAINTENANCE']);
    }

    private function ri(int $id, string $no, string $date, string $desc, float $qty, string $ket, string $from, string $code = 'X.2'): Ri
    {
        return Ri::forceCreate(['accurate_apinvoice_id' => $id, 'accurate_seq' => 0, 'no_ri' => $no, 'tgl_ri' => $date, 'divisi' => 'NV',
            'kode_barang' => $code, 'deskripsi_barang' => $desc, 'kuantitas' => $qty, 'satuan' => 'PCS', 'vendor' => $from, 'keterangan' => $ket]);
    }

    public function test_tyre_changes_come_from_npbg_and_close_with_the_used_tyre_ri(): void
    {
        $this->assertSame('DEPAN KANAN', TyreFromAccurate::position('PENGGANTIAN BAN BAGIAN DEPAN KANAN AKRENA MELETUS'));
        $this->assertSame('BELAKANG KIRI LUAR', TyreFromAccurate::position('BAN BAGIAN BELAKANG KIRI LUAR KARENA SUDAH HALUS'));
        $this->assertSame('5IA1030', TyreFromAccurate::serial('… | NO SERI BAN LAMA: 5IA1030', ['LAMA', 'BEKAS']));
        $this->assertNull(TyreFromAccurate::serial('NO SERI BAN LAMA: MENYUSUL', ['LAMA']), 'placeholder, not a serial');
        $this->assertSame('A21-203413 (M-0325)', TyreFromAccurate::serial('… | NO SERI BAN VUL: A21-203413 (M-0325) | NO SERI BAN', ['VUL']));

        // two tyres to a truck, one to a customer (not a vehicle → ignored)
        $this->npbg(1, 'NA/26/IX/054', '2026-09-07', 'BAN LUAR STEEL - ROVELO / 10.00-R20', 2,
            'PENGGANTIAN BAN BAGIAN BELAKANG KANAN | NO SERI BAN LAMA: 5IA1030', 'EKOR TRAILER SIG-01');
        $this->npbg(2, 'NA/26/IX/086', '2026-09-10', '(RUSAK) BAN LUAR STEEL - HIXIH', 1, 'UNTUK DIPINJAMKAN KE CDO', 'PT. CANDI OXYGEN GASES');
        // one old tyre back, quoting the NPBG
        $this->ri(10, 'RI/NV/26/IX/039', '2026-09-25', '(BEKAS) BAN LUAR STEEL - ROVELO', 1,
            'PENGEMBALIAN BAN LUAR BEKAS PENGGANTIAN KENDARAAN SESUAI, REF NPBG: NA/26/IX/054 (07.09.2026)', 'EKOR TRAILER SIG-01');

        $r = app(TyreFromAccurate::class)->derive();
        $this->assertSame(2, $r['inserted']);
        $this->assertSame(['CLEAR', 'PENDING_RI'], TyreChange::orderBy('change_seq')->pluck('status')->all());
        $first = TyreChange::orderBy('change_seq')->first();
        $this->assertSame('EKOR TRAILER SIG-01', $first->nopol_raw);
        $this->assertSame('BELAKANG KANAN', $first->position);
        $this->assertSame('5IA1030', $first->old_serial_raw);
        $this->assertSame('(BEKAS) BAN LUAR STEEL - ROVELO', $first->old_tyre_desc);

        // a serial typed by a person survives the next sync; nothing duplicated
        $this->actingAsRole('admin_gudang');
        $this->putJson("/api/tyre-changes/{$first->id}", ['new_serial_raw' => '2320611829 (M-0523)'])->assertOk();
        $again = app(TyreFromAccurate::class)->derive();
        $this->assertSame(0, $again['inserted']);
        $this->assertSame('2320611829 (M-0523)', $first->fresh()->new_serial_raw);
        $this->getJson('/api/tyre-changes')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.out_npbg', 'NA/26/IX/054');
    }

    public function test_maintenance_uses_excel_numbering_nopol_and_accurate_npbg(): void
    {
        Site::factory()->create();
        $this->npbg(1, 'NA/25/VIII/009', '2025-08-01', 'CAT BESI', 2, 'UNTUK REPARASI BAK', 'W 8747 PD (HINO DUTRO)');
        $this->actingAsRole('admin_gudang');

        $order = $this->postJson('/api/maintenance-orders', [
            'nopol_raw' => 'w 8747 pd (hino dutro)', 'report_date' => '2025-07-27', 'problem_summary' => 'REKONDISI BAK',
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#^MK/25/VII/\d{3}$#', $order['number']);
        $this->assertSame('W 8747 PD (HINO DUTRO)', $order['nopol']);

        $this->postJson("/api/maintenance-orders/{$order['id']}/subs", [
            'workshop_raw' => 'SIG', 'problem_detail' => 'REKONDISI ULANG BAK BELAKANG', 'npbg_no' => 'NA/25/VIII/009',
        ])->assertOk()->assertJsonPath('data.subs.0.npbg.lines.0.description', 'CAT BESI');

        $this->postJson("/api/maintenance-orders/{$order['id']}/subs", ['npbg_no' => 'NA/99/I/999'])
            ->assertStatus(422)->assertJsonValidationErrors('npbg_no');
    }

    public function test_manufacturing_uses_excel_numbering_location_and_accurate_refs(): void
    {
        Site::factory()->create();
        $this->npbg(1, 'NA/25/VII/251', '2025-07-24', 'PLAT CS', 4, 'UNTUK FABRIKASI CRADLE', 'MAINTENANCE (SDA)');
        $this->ri(2, 'RI/NV/25/VIII/019', '2025-08-04', 'CRADLE CARBON STEEL 3x2', 1, 'CRADLE 3x2 SUDAH SELESAI', 'MAINTENANCE (SDA)');
        $this->actingAsRole('admin_gudang');

        $mo = $this->postJson('/api/manufacturing-orders', [
            'kind' => 'ASSEMBLY', 'date' => '2025-07-25', 'product_name' => 'CRADLE CARBON STEEL 3x2', 'location' => 'SIG',
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('#^MA/25/VII/\d{3}$#', $mo['number']);
        $this->assertSame('SIG', $mo['location']);

        $sub = $this->postJson("/api/manufacturing-orders/{$mo['id']}/subs", [
            'process' => 'CRA(1) - PEMBUATAN RANGKA', 'serial_no_raw' => 'SIG-58', 'npbg_no' => 'NA/25/VII/251',
        ])->assertOk()->json('data.subs.0');
        $this->postJson("/api/manufacturing-subs/{$sub['id']}/complete", ['ri_no' => 'RI/NV/25/VIII/019', 'note_end' => 'SELESAI'])
            ->assertOk()->assertJsonPath('data.subs.0.ri.lines.0.description', 'CRADLE CARBON STEEL 3x2');

        // jasa done outside: the Excel "Lokasi" is enough
        $this->postJson('/api/manufacturing-orders', ['kind' => 'JASA', 'location' => 'PT. BANGKIT LAKSANA JAYA', 'product_name' => 'VULKANISIR'])
            ->assertCreated()->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'MJ/'));
    }

    public function test_lookups_for_the_forms(): void
    {
        $this->npbg(1, 'NA/26/IX/202', '2026-09-26', 'BAN LUAR STEEL - GITI', 1, 'GANTI BAN', 'W 8985 QF (HINO DUTRO)');
        $this->npbg(2, 'NA/26/IX/203', '2026-09-26', 'KERTAS', 1, 'ATK', 'ACCOUNTING (SDA)');
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/tracking/vehicles')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'W 8985 QF (HINO DUTRO)');
        $this->getJson('/api/tracking/document?type=npbg&no='.urlencode('na/26/ix/202'))->assertOk()
            ->assertJsonPath('data.party', 'W 8985 QF (HINO DUTRO)');
        $this->getJson('/api/tracking/document?type=ri&no=RI/NV/99/I/001')->assertNotFound();
    }

    public function test_lend_follows_excel_columns_and_keeps_what_a_person_typed(): void
    {
        Item::factory()->create(['code' => 'SSP.0244']);
        $this->npbg(1, 'NA/26/IX/174', '2026-09-22', 'REDUCING NIPPLE', 1, 'UNTUK DIPINJAM PAKAIKAN KEPERLUAN CURAH HARIAN',
            'PT. SAMUDERA ASIA SEJAHTERA', 'RICCO', 'SSP.0244');
        app(LendBorrowFromAccurate::class)->derive();
        $lend = LendTransaction::firstOrFail();
        $this->assertSame('RICCO', $lend->requester_name);

        $this->actingAsRole('admin_gudang');
        $this->putJson("/api/lend/{$lend->id}", ['est_days' => 7, 'purpose' => 'RELASI'])->assertOk()
            ->assertJsonPath('data.est_days', 7)
            ->assertJsonPath('data.due_date', '2026-09-29');

        // the RI that brings it back fills "Tanda Kembali" / "Keterangan Kembali"; the typed Est. stays
        $this->ri(9, 'RI/NV/26/IX/050', '2026-09-28', 'REDUCING NIPPLE', 1, 'KEMBALI SETELAH DIPINJAMKAN KE PT. SAMUDERA', 'PT. SAMUDERA ASIA SEJAHTERA', 'SSP.0244');
        app(LendBorrowFromAccurate::class)->derive();
        $lend->refresh();
        $this->assertSame(7, (int) $lend->est_days);
        $this->assertSame('RETURNED', $lend->status);
        $this->assertSame('RI/NV/26/IX/050', $lend->return_refs);
        $this->assertSame('KEMBALI SETELAH DIPINJAMKAN KE PT. SAMUDERA', $lend->return_note);
    }
}
