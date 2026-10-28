<?php

namespace Tests\Feature\PurchaseReturn;

use App\Models\PurchaseReturn;
use App\Models\Ri;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Retur Pembelian: from an RI → sent to the vendor → replaced / credited. */
class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** RI/NA/26/X/050 from PT MAJU: 10 bearings + 4 seals. */
    private function ri(): array
    {
        $base = ['no_ri' => 'RI/NA/26/X/050', 'tgl_ri' => '2026-10-20', 'vendor' => 'PT MAJU', 'no_po' => 'PO/NA/26/X/010', 'satuan' => 'PCS'];
        $a = Ri::create($base + ['accurate_apinvoice_id' => 900, 'accurate_seq' => 1, 'kode_barang' => 'BRG.1', 'deskripsi_barang' => 'BEARING 6205', 'kuantitas' => 10, 'harga_satuan' => 25000]);
        $b = Ri::create($base + ['accurate_apinvoice_id' => 900, 'accurate_seq' => 2, 'kode_barang' => 'BRG.2', 'deskripsi_barang' => 'SEAL 40', 'kuantitas' => 4, 'harga_satuan' => 5000]);

        return [$a, $b];
    }

    public function test_return_from_an_ri_send_and_settle(): void
    {
        [$a, $b] = $this->ri();
        $this->actingAsRole('super_admin');

        $this->getJson('/api/purchase-returns/ri-search?q=maju')->assertOk()->assertJsonPath('data.0.no_ri', 'RI/NA/26/X/050')->assertJsonPath('data.0.line_count', 2);
        $lines = $this->getJson('/api/purchase-returns/ri-lines?no_ri='.urlencode('RI/NA/26/X/050'))->assertOk()->json('data.lines');
        $this->assertSame([10.0, 4.0], array_map(fn ($l) => (float) $l['returnable'], $lines));

        // never more than received; the reason and a photo of the goods are required (no settlement to pick)
        $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'reason' => 'Rusak', 'photo' => self::PNG,
            'lines' => [['ri_id' => $a->id, 'qty' => 11]]])->assertStatus(422);
        $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'reason' => 'Rusak', 'lines' => [['ri_id' => $a->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'photo' => self::PNG, 'lines' => [['ri_id' => $a->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $made = $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'reason' => 'Rusak saat diterima',
            'photo' => self::PNG, 'lines' => [['ri_id' => $a->id, 'qty' => 3], ['ri_id' => $b->id, 'qty' => 0]]])
            ->assertCreated()->assertJsonPath('data.status', 'DIBUAT')->assertJsonPath('data.vendor', 'PT MAJU')
            ->assertJsonPath('data.no_po', 'PO/NA/26/X/010')->assertJsonPath('data.lines_count', 1)->json('data');
        $this->assertMatchesRegularExpression('#^RTR/\d{2}/[IVX]+/001$#', $made['number']);
        $this->assertSame([75000.0, ['barang']], [(float) $made['total_value'], $made['photos']]);

        // what is left to return counts the open return
        $left = $this->getJson('/api/purchase-returns/ri-lines?no_ri='.urlencode('RI/NA/26/X/050'))->json('data.lines');
        $this->assertSame(7.0, (float) $left[0]['returnable']);
        $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'reason' => 'x', 'photo' => self::PNG,
            'lines' => [['ri_id' => $a->id, 'qty' => 8]]])->assertStatus(422);

        // settle only after it was sent
        $this->postJson("/api/purchase-returns/{$made['id']}/resolve", ['ref' => 'RI/NA/26/X/060'])->assertStatus(422);
        $this->postJson("/api/purchase-returns/{$made['id']}/send", ['note' => 'via ekspedisi', 'photo' => self::PNG])->assertOk()
            ->assertJsonPath('data.status', 'DIKIRIM')->assertJsonPath('data.sent_note', 'via ekspedisi');
        $this->postJson("/api/purchase-returns/{$made['id']}/cancel")->assertStatus(422); // already sent
        $done = $this->postJson("/api/purchase-returns/{$made['id']}/resolve", ['ref' => 'RI/NA/26/X/060', 'note' => 'sudah diganti'])->assertOk()
            ->assertJsonPath('data.status', 'SELESAI')->assertJsonPath('data.resolution_ref', 'RI/NA/26/X/060')->json('data');
        $this->assertEqualsCanonicalizing(['barang', 'kirim'], $done['photos']);
        $this->get("/api/purchase-returns/{$made['id']}/photo/kirim")->assertOk();

        $this->getJson('/api/purchase-returns?search=bearing')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.total_qty', 3);
    }

    public function test_a_cancelled_return_frees_the_quantity_and_access_is_by_permission(): void
    {
        [$a] = $this->ri();
        $this->actingAsRole('super_admin');
        $id = $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'reason' => 'Kelebihan kirim', 'photo' => self::PNG,
            'lines' => [['ri_id' => $a->id, 'qty' => 10]]])->assertCreated()->json('data.id');
        $this->postJson("/api/purchase-returns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'DIBATALKAN');
        $this->assertSame(10.0, (float) $this->getJson('/api/purchase-returns/ri-lines?no_ri='.urlencode('RI/NA/26/X/050'))->json('data.lines.0.returnable'));

        // the warehouse and purchasing handle returns; a requester does not
        $this->actingAsRole('karyawan');
        $this->getJson('/api/purchase-returns')->assertForbidden();
        $this->postJson('/api/purchase-returns', ['no_ri' => 'RI/NA/26/X/050', 'resolution' => 'GANTI_BARANG', 'reason' => 'x', 'lines' => [['ri_id' => $a->id, 'qty' => 1]]])
            ->assertForbidden();
        $this->assertSame(1, PurchaseReturn::count());
    }
}
