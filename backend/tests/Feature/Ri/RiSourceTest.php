<?php

namespace Tests\Feature\Ri;

use App\Models\Item;
use App\Models\Npbg;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use App\Services\Tracking\UsedReturnSheets;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A STOCKWISE RI: from a PO, from used goods coming back (Pengembalian Bekas), or manual (tied to nothing). */
class RiSourceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = Item::factory()->create(['code' => 'SPR.0001', 'description' => 'BAN LUAR 7.50-16', 'is_active' => true]);
        app(StockLedgerService::class)->openingBalance($this->item->id, Warehouse::factory()->create()->id, 5);
    }

    private function stock(): float
    {
        return (float) DB::table('inventory')->where('item_id', $this->item->id)->sum('actual_qty');
    }

    /** an NPBG line ticked "Kembali Bekas": a PENDING row in Tracking Pengembalian Bekas */
    private function usedGoodsOut(): int
    {
        $n = Npbg::create(['accurate_arinvoice_id' => 901, 'accurate_seq' => 0, 'no_npbg' => 'NPBG/NA/26/X/090', 'tgl_npbg' => '2026-10-20',
            'kode_barang' => 'SPR.0001', 'deskripsi_barang' => 'BAN LUAR 7.50-16', 'kuantitas' => 2, 'satuan' => 'PCS', 'pelanggan' => 'B 1234 XY']);
        $this->actingAsRole('super_admin');
        $this->putJson("/api/npbg/{$n->id}/kembali-bekas", ['checked' => true])->assertOk()->assertJsonPath('data.kembali_bekas_status', 'PENDING');

        return (int) DB::table(UsedReturnSheets::OTHER)->where('npbg_id', $n->id)->value('id');
    }

    public function test_used_goods_back_clear_their_tracking_row_without_adding_stock(): void
    {
        $row = $this->usedGoodsOut();
        $pick = $this->getJson('/api/ri/used-return-lines?search=7.50')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame([$row, 'NPBG/NA/26/X/090', 'SPR.0001', 'B 1234 XY'], [$pick['id'], $pick['npbg_no'], $pick['kode_barang'], $pick['pelanggan']]);

        // a photo is the proof, and which used goods come back is required
        $this->postJson('/api/ri', ['sumber' => 'BEKAS', 'divisi' => 'NV', 'deskripsi_barang' => 'BAN LUAR BEKAS', 'kuantitas' => 2, 'photos' => [self::PHOTO]])
            ->assertStatus(422)->assertJsonValidationErrors('used_return_row_id');
        $ri = $this->postJson('/api/ri', ['sumber' => 'BEKAS', 'used_return_row_id' => $row, 'divisi' => 'NV', 'kode_barang' => 'SPR.0001',
            'deskripsi_barang' => 'BAN LUAR 7.50-16 (BEKAS)', 'kuantitas' => 2, 'photos' => [self::PHOTO]])->assertCreated()->json('data');
        $this->assertSame(['BEKAS', $row, 'Pengembalian bekas NPBG NPBG/NA/26/X/090', 'PCS'], [$ri['sumber'], $ri['used_return_row_id'], $ri['keterangan'], $ri['satuan']]);

        $tracked = DB::table(UsedReturnSheets::OTHER)->find($row);
        $this->assertSame([$ri['no_ri'], 'CLEAR'], [$tracked->ri_no, $tracked->status], 'the RI back and its photo: CLEAR');
        $this->assertSame(5.0, $this->stock(), 'used goods are not new stock');
        $this->getJson('/api/ri/used-return-lines')->assertJsonCount(0, 'data');
        $this->getJson("/api/npbg/{$tracked->npbg_id}")->assertJsonPath('data.kembali_bekas_status', 'CLEAR');
        // only once
        $this->postJson('/api/ri', ['sumber' => 'BEKAS', 'used_return_row_id' => $row, 'divisi' => 'NV', 'deskripsi_barang' => 'X', 'kuantitas' => 1, 'photos' => [self::PHOTO]])
            ->assertStatus(422)->assertJsonValidationErrors('used_return_row_id');
        $this->getJson('/api/ri?sumber=BEKAS')->assertJsonCount(1, 'data');
    }

    public function test_a_manual_ri_is_tied_to_nothing_and_adds_its_master_item_to_stock(): void
    {
        $this->actingAsRole('super_admin');
        $ri = $this->postJson('/api/ri', ['sumber' => 'MANUAL', 'divisi' => 'NV', 'kode_barang' => 'SPR.0001', 'deskripsi_barang' => 'BAN LUAR 7.50-16',
            'kuantitas' => 3, 'keterangan' => 'Titipan cabang', 'used_return_row_id' => null, 'photos' => [self::PHOTO]])->assertCreated()->json('data');
        $this->assertSame(['MANUAL', null], [$ri['sumber'], $ri['used_return_row_id']]);
        $this->assertSame(8.0, $this->stock());
        // free goods, not a master item: no stock, still an RI
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'Sample brosur vendor', 'kuantitas' => 10, 'photos' => [self::PHOTO]])
            ->assertCreated()->assertJsonPath('data.sumber', 'MANUAL');
        $this->getJson('/api/ri?sumber=MANUAL')->assertJsonCount(2, 'data');
        $this->getJson('/api/ri?sumber=PO')->assertJsonCount(0, 'data');
        // "Dari PO" needs its PO line
        $this->postJson('/api/ri', ['sumber' => 'PO', 'divisi' => 'NV', 'deskripsi_barang' => 'X', 'kuantitas' => 1, 'photos' => [self::PHOTO]])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_order_item_id');
    }

    public function test_ri_from_a_po_takes_its_item_list_and_may_receive_part_of_it(): void
    {
        $po = \App\Models\PurchaseOrder::factory()->create(['status' => 'APPROVED', 'number' => 'PO/MANUAL/26/X/001']);
        $ban = \App\Models\PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'item_id' => $this->item->id, 'description_raw' => 'BAN LUAR 7.50-16', 'qty' => 10, 'unit_price' => 1500000, 'line_total' => 15000000]);
        $velg = \App\Models\PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'description_raw' => 'VELG 16', 'qty' => 4, 'unit_price' => 500000, 'line_total' => 2000000]);
        $this->actingAsRole('super_admin');

        // search the PO: all its lines, with how much is left
        $found = $this->getJson('/api/ri/po-orders?search=velg')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertEquals([$po->number, 2, 10, 0], [$found['number'], count($found['lines']), $found['lines'][0]['remaining'], $found['lines'][0]['received']]);

        $body = ['purchase_order_id' => $po->id, 'divisi' => 'NA', 'no_surat_jalan' => 'SJ-001',
            'documents' => [['data' => 'data:application/pdf;base64,JVBERi0xLjQK', 'name' => 'surat-jalan.pdf']]];
        // the proof is per item: no photo for the tyres, refused
        $this->postJson('/api/ri/from-po', $body + ['lines' => [['purchase_order_item_id' => $ban->id, 'qty' => 5]]])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->postJson('/api/ri/from-po', $body + ['lines' => [['purchase_order_item_id' => $ban->id, 'qty' => 0]]])->assertStatus(422)->assertJsonValidationErrors('lines');
        // half of the tyres came, the rims not yet: one RI of one line
        $ri = $this->postJson('/api/ri/from-po', $body + ['lines' => [['purchase_order_item_id' => $ban->id, 'qty' => 5, 'photos' => [self::PHOTO]], ['purchase_order_item_id' => $velg->id, 'qty' => 0]]])
            ->assertCreated()->json('data');
        $this->assertSame(['PO', $po->number, 'SJ-001', ['foto1']], [$ri['sumber'], $ri['no_po'], $ri['no_surat_jalan'], $ri['photos']]);
        $this->assertSame('surat-jalan.pdf', $ri['documents'][0]['file_name']);
        $this->assertSame(['PARTIAL_RECEIVED', 5.0, 10.0], [$po->refresh()->status, (float) $ban->refresh()->qty_received, $this->stock()]);
        $this->get("/api/ri/{$ri['id']}/photo/doc1")->assertOk();
        // the PO list says it: ordered 10, received 5 ("10/5")
        $row = collect($this->getJson('/api/purchase-orders?search=BAN')->json('data'))->firstWhere('id', $ban->id);
        $this->assertEquals([10, 5], [$row['qty'], $row['received']]);

        // the rest with a second delivery note: one RI of two lines, the PO is done
        $two = $this->postJson('/api/ri/from-po', ['no_surat_jalan' => 'SJ-002'] + $body + ['lines' => [['purchase_order_item_id' => $ban->id, 'qty' => 5, 'photos' => [self::PHOTO]], ['purchase_order_item_id' => $velg->id, 'qty' => 4, 'no_seri' => 'V1-V4', 'photos' => [self::PHOTO, self::PHOTO]]]])
            ->assertCreated()->json('data');
        $shown = $this->getJson("/api/ri/{$two['id']}")->json('data');
        $this->assertCount(2, $shown['lines']);
        $this->assertSame([['foto1'], ['foto1', 'foto2']], array_column($shown['lines'], 'photos'), 'each item its own proof');
        $this->get("/api/ri/{$shown['lines'][1]['id']}/photo/foto2")->assertOk();
        $this->assertSame('RECEIVED', $po->refresh()->status);
        $this->getJson('/api/ri/po-orders')->assertJsonCount(0, 'data');
        // the same delivery note again: never
        $po->update(['status' => 'PARTIAL_RECEIVED']);
        $ban->update(['qty_received' => 9]);
        $this->postJson('/api/ri/from-po', ['no_surat_jalan' => 'SJ-002'] + $body + ['lines' => [['purchase_order_item_id' => $ban->id, 'qty' => 1, 'photos' => [self::PHOTO]]], 'allow_duplicate' => true])
            ->assertStatus(422)->assertJsonValidationErrors('duplicate');
    }
}
