<?php

namespace Tests\Feature\Purchasing;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\Ppb;
use App\Models\PpbDraft;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** STOCKWISE purchasing: PPB Manager → Direktur, PO by the Direktur, RI in parts, Restock without double buying. */
class StockwisePurchasingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Item $item;

    /** a 1x1 PNG: the proof photo every manual RI needs */
    private const PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['stockwise.stockwise_ledger_since' => now()->subDay()->toDateTimeString()]);
        $this->item = Item::factory()->create(['code' => 'ATK.0001', 'description' => 'KERTAS HVS A4', 'is_active' => true]);
        Warehouse::factory()->create();
    }

    /** A Request Barang of $qty with no stock: approved → its Draft PPB → submitted as PPB $no. */
    private function requestToPpb(float $qty, string $no): array
    {
        $this->actingAsRole('karyawan');
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'kertas', 'qty' => $qty]]])->json('data');
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$req['lines'][0]['id'] => ['item_id' => $this->item->id]]])->assertOk();
        $this->actingAsRole('direktur');
        $this->postJson("/api/item-requests/{$req['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.status', 'MENUNGGU_PENGADAAN');
        $draft = PpbDraft::where('item_request_id', $req['id'])->sole();
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/ppb-drafts/{$draft->id}/submit", ['no_ppb' => $no, 'tgl_ppb' => now()->toDateString()])->assertOk();

        return [$req['id'], Ppb::where('no_ppb', $no)->value('id')];
    }

    public function test_stockwise_ppb_two_steps_then_po_by_the_direktur_then_ri_in_parts(): void
    {
        [$reqId, $ppbId] = $this->requestToPpb(5, 'PPB/SW/001');
        $this->getJson("/api/ppb/{$ppbId}")->assertJsonPath('data.approval.status', 'MENUNGGU')->assertJsonPath('data.approval.step', 'MANAGER');

        // Accurate's one-step approvers cannot take a STOCKWISE step; the Direktur already sees it (bypass), the Manager acts first
        $this->actingAsRole('manager', ['name' => 'PAK MANAGER']);
        $this->getJson('/api/approvals/purchasing')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.ppb.0.step', 'MANAGER');
        $this->getJson('/api/approvals/count')->assertJsonPath('data.total', 1);
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU_DIREKTUR');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertStatus(422); // not their step any more
        $this->getJson('/api/approvals/purchasing')->assertJsonPath('data.total', 0);
        $this->assertSame(0, PurchaseOrder::count());

        $this->actingAsRole('direktur', ['name' => 'BU DIREKTUR']);
        $this->getJson('/api/approvals/purchasing')->assertJsonPath('data.ppb.0.step', 'DIREKTUR');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'DISETUJUI');
        $entry = PurchaseOrder::sole();
        $this->assertSame(['MENUNGGU_PO', 'PPB/SW/001'], [$entry->status, $entry->from_ppb_approval]);

        // purchasing completes it (vendor, price) → waits for the Direktur; purchasing cannot approve it
        $vendor = Vendor::factory()->create();
        $purchasing = $this->actingAsRole('purchasing');
        $line = $entry->items()->sole();
        $po = $this->getJson("/api/purchase-orders/{$entry->id}")->assertJsonPath('data.can_complete', true)->json('data');
        $this->putJson("/api/purchase-orders/{$entry->id}/complete", ['vendor_id' => $vendor->id, 'lines' => [['id' => $line->id, 'qty' => 5, 'unit_price' => 1000]]])
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.approval_status', 'MENUNGGU_DIREKTUR')->assertJsonPath('data.total', 5000);
        $this->postJson("/api/purchase-orders/{$entry->id}/approve")->assertStatus(422);

        // the Direktur sends it back, purchasing fixes it, the Direktur approves
        $this->actingAsRole('direktur');
        $this->getJson('/api/approvals/purchasing')->assertJsonPath('data.po.0.id', $entry->id);
        $this->postJson("/api/purchase-orders/{$entry->id}/reject", ['note' => 'Harga terlalu mahal'])->assertOk()->assertJsonPath('data.approval_status', 'DIKEMBALIKAN');
        $this->postJson("/api/purchase-orders/{$entry->id}/approve")->assertStatus(422);
        $this->actingAs($purchasing, 'sanctum');
        $this->putJson("/api/purchase-orders/{$entry->id}/complete", ['vendor_id' => $vendor->id, 'lines' => [['id' => $line->id, 'qty' => 5, 'unit_price' => 900]]])->assertOk();
        $this->actingAsRole('direktur');
        $done = $this->postJson("/api/purchase-orders/{$entry->id}/approve")->assertOk()->json('data');
        $this->assertSame(['APPROVED', 'DISETUJUI', 4500.0], [$done['status'], $done['approval_status'], (float) $done['total']]);
        $this->assertSame(['DIAJUKAN', 'REJECT', 'DIAJUKAN', 'APPROVE'], array_column($done['approval_log'], 'action'));
        $this->assertSame('MENUNGGU_BARANG', ItemRequest::find($reqId)->status, 'the request knows its goods were ordered');
        $this->actingAsRole('super_admin');
        $this->assertSame(['PO dikirim, menunggu RI', $entry->number], [$this->getJson("/api/item-requests/{$reqId}")->json('data.lines.0.ppb.stage'), $this->getJson("/api/item-requests/{$reqId}")->json('data.lines.0.ppb.po_number')]);

        // purchasing uploads the proof of purchase (photo / PDF) → the PO is "Sudah dibeli"
        $this->actingAsRole('purchasing');
        $this->getJson("/api/purchase-orders/{$entry->id}")->assertJsonPath('data.can_upload_proof', true)->assertJsonPath('data.proof', null);
        $this->post("/api/purchase-orders/{$entry->id}/proof", ['file' => UploadedFile::fake()->create('nota.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $bought = $this->post("/api/purchase-orders/{$entry->id}/proof", ['file' => UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        $this->assertSame(['DIBELI', 'DISETUJUI', 'nota.pdf'], [$bought['status'], $bought['approval_status'], $bought['proof']['file_name']]);
        $this->assertSame('DIBELI', array_column($bought['approval_log'], 'action')[4]);
        $this->get("/api/purchase-orders/{$entry->id}/proof")->assertOk();
        $this->actingAsRole('super_admin');
        $this->assertSame('Sudah dibeli, menunggu RI', $this->getJson("/api/item-requests/{$reqId}")->json('data.lines.0.ppb.stage'));

        // goods come in two RI against the PO line; every RI needs 1 to 3 proof photos
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 2, 'purchase_order_item_id' => $line->id])
            ->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 2, 'purchase_order_item_id' => $line->id, 'photos' => array_fill(0, 4, self::PHOTO)])
            ->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->assertSame(0.0, (float) $line->refresh()->qty_received, 'a refused RI receives nothing');
        $this->getJson('/api/ri/po-lines')->assertOk()->assertJsonPath('data.0.remaining', 5);
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 2, 'purchase_order_item_id' => $line->id, 'photos' => [self::PHOTO]])
            ->assertCreated()->assertJsonPath('data.no_po', $entry->number)->assertJsonPath('data.photos', ['foto1']);
        $firstRi = \App\Models\Ri::latest('id')->first();
        $this->get("/api/ri/{$firstRi->id}/photo/foto1")->assertOk();
        $this->get("/api/ri/{$firstRi->id}/photo/foto2")->assertNotFound();
        $this->assertSame(['PARTIAL_RECEIVED', 2.0], [$entry->refresh()->status, (float) $line->refresh()->qty_received]);
        // the 2 that arrived are given to the request at once: ready for the serah terima
        // held for the request (still in stock until the serah terima)
        $this->assertSame([2.0, 2.0], [(float) DB::table('inventory')->where('item_id', $this->item->id)->sum('actual_qty'), (float) DB::table('inventory')->where('item_id', $this->item->id)->sum('reserved_qty')]);
        $this->assertSame('DISIAPKAN', ItemRequest::find($reqId)->status, 'part of it is being prepared by Gudang now');
        // 3 left on the PO, the vendor sends 4: accepted (the extra goes to stock) and the line is done
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 4, 'purchase_order_item_id' => $line->id, 'photos' => [self::PHOTO]])->assertCreated();
        $this->assertSame(['RECEIVED', 'RECEIVED', 6.0], [$entry->refresh()->status, $line->refresh()->line_status, (float) $line->qty_received]);
        // nothing more on this PO line
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 1, 'purchase_order_item_id' => $line->id, 'photos' => [self::PHOTO]])->assertStatus(422);
        $this->assertSame('Diterima (RI)', $this->getJson("/api/item-requests/{$reqId}")->json('data.lines.0.ppb.stage'));
        $this->getJson('/api/ri/po-lines')->assertJsonCount(0, 'data');
        $this->actingAsRole('purchasing');
        $this->getJson("/api/purchase-orders/{$entry->id}")->assertOk()->assertJsonCount(2, 'data.items.0.received_via');
    }

    public function test_manager_and_direktur_cannot_change_ppb_quantities(): void
    {
        [, $ppbId] = $this->requestToPpb(5, 'PPB/SW/003');
        // a quantity that is not right goes back to admin ("Kembalikan"), the approver does not change it
        $this->actingAsRole('manager');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE', 'quantities' => [$ppbId => 4], 'note' => 'Stok lama masih ada 1'])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU_DIREKTUR');
        $this->actingAsRole('direktur');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE', 'quantities' => [$ppbId => 6]])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'DISETUJUI');
        $this->assertSame([5.0, 5.0], [(float) Ppb::find($ppbId)->kuantitas, (float) PurchaseOrder::sole()->items()->sole()->qty]);
    }

    public function test_a_ppb_sent_back_is_revised_by_admin_and_approved_again(): void
    {
        [, $ppbId] = $this->requestToPpb(5, 'PPB/SW/004');
        $this->actingAsRole('manager');
        // sending it back needs a note
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'RETURN'])->assertStatus(422)->assertJsonValidationErrors('note');
        $back = $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'RETURN', 'note' => 'Qty kebanyakan, cek stok lama'])->assertOk()->json('data');
        $this->assertSame(['DIKEMBALIKAN', 'ADMIN', false], [$back['approval']['status'], $back['approval']['step'], $back['can_decide']]);
        $this->getJson('/api/approvals/purchasing')->assertJsonPath('data.total', 0);
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertStatus(422); // admin's turn now
        // admin revises the quantity (a note is required then) and sends it again: the Manager's step again
        $this->actingAsRole('admin_gudang');
        $this->getJson("/api/ppb/{$ppbId}")->assertJsonPath('data.can_resubmit', true);
        $this->postJson("/api/ppb/{$ppbId}/resubmit", ['quantities' => [$ppbId => 3]])->assertStatus(422)->assertJsonValidationErrors('note');
        $again = $this->postJson("/api/ppb/{$ppbId}/resubmit", ['quantities' => [$ppbId => 3], 'note' => 'Stok lama ada 2'])->assertOk()->json('data');
        $this->assertSame(['MENUNGGU', 'MANAGER'], [$again['approval']['status'], $again['approval']['step']]);
        $this->assertSame(['DIAJUKAN', 'RETURN', 'DIAJUKAN_ULANG'], array_column($again['approval_log'], 'action'));
        $this->assertSame(3.0, (float) Ppb::find($ppbId)->kuantitas);
        $this->postJson("/api/ppb/{$ppbId}/resubmit", ['note' => 'lagi'])->assertStatus(422); // not sent back any more
        $this->actingAsRole('manager');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU_DIREKTUR');
        // the Direktur may send it back too
        $this->actingAsRole('direktur');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'RETURN', 'note' => 'Vendor belum jelas'])->assertOk()->assertJsonPath('data.approval.status', 'DIKEMBALIKAN');
    }

    public function test_the_direktur_bypasses_the_manager_and_a_rejection_frees_the_request(): void
    {
        [$reqId, $ppbId] = $this->requestToPpb(3, 'PPB/SW/002');
        $this->actingAsRole('direktur');
        $done = $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk()->json('data');
        $this->assertSame(['DISETUJUI', ['DIAJUKAN', 'APPROVE_MANAGER', 'APPROVE']], [$done['approval']['status'], array_column($done['approval_log'], 'action')]);
        $this->assertStringContainsString('bypass', $done['approval_log'][1]['note']);

        // withdrawn before any PO: the entry goes, the request is no longer waiting for purchasing
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'REJECT', 'note' => 'Tidak jadi'])->assertOk()->assertJsonPath('data.approval.status', 'DITOLAK');
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertNotSame('MENUNGGU_PENGADAAN', ItemRequest::find($reqId)->status);
    }

    public function test_restock_leaves_out_what_is_already_ordered(): void
    {
        $this->actingAsRole('admin_gudang');
        $first = $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $this->item->id, 'qty' => 4]]])->assertCreated()->json('data');
        // already in an open draft
        $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $this->item->id]]])->assertStatus(422)->assertJsonFragment(['items' => ["Tidak ada yang perlu di-restock: ATK.0001 sudah ada di Draft {$first['number']}."]]);
        $this->getJson('/api/inventory/analysis?search=ATK.0001')->assertOk()->assertJsonPath('data.0.on_order.draft', 4);

        // submitted as a PPB: still on order (the suggestion of 1 is covered)
        $this->postJson("/api/ppb-drafts/{$first['id']}/submit", ['no_ppb' => 'PPB/SW/003', 'tgl_ppb' => now()->toDateString()])->assertOk();
        $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $this->item->id]]])->assertStatus(422);
        // asked for explicitly: allowed, the basis says what is on order
        $made = $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $this->item->id, 'qty' => 2]]])->assertCreated()->json('data');
        $this->assertStringContainsString('Dalam pesanan 4', $made['lines'][0]['basis']);
    }

    public function test_the_draft_ppb_list_shows_every_item_with_its_source_and_makes_one_ppb(): void
    {
        $other = Item::factory()->create(['code' => 'ATK.0002', 'description' => 'PULPEN', 'is_active' => true]);
        // a Request Barang without stock → its line in the list; Restock of another item → a Safety Stock line
        $this->actingAsRole('karyawan', ['name' => 'SITI']);
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'kertas', 'qty' => 3]]])->json('data');
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$req['lines'][0]['id'] => ['item_id' => $this->item->id]]])->assertOk();
        $this->actingAsRole('direktur');
        $this->postJson("/api/item-requests/{$req['id']}/approval", ['action' => 'APPROVE'])->assertOk();
        $this->actingAsRole('admin_gudang');
        // ATK.0001 is already in the list (from the request): Restock leaves it out
        $made = $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $other->id, 'qty' => 10], ['item_id' => $this->item->id, 'qty' => 2]]])->assertCreated()->json();
        $this->assertSame(['ATK.0001'], array_column($made['skipped'], 'code'));

        $list = $this->getJson('/api/ppb-draft-lines')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame([['REQUEST', $req['number'], 'SITI'], ['RESTOCK', null, null]],
            array_map(fn ($l) => [$l['source']['type'], $l['source']['item_request_number'], $l['source']['requester']], $list));
        $this->getJson('/api/ppb-draft-lines?source=REQUEST')->assertJsonPath('meta.total', 1);

        // a third one taken off the list: its draft is cancelled
        $third = Item::factory()->create(['code' => 'ATK.0003', 'description' => 'SPIDOL', 'is_active' => true]);
        $extra = $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $third->id, 'qty' => 1]]])->assertCreated()->json('data');
        $this->deleteJson("/api/ppb-draft-lines/{$extra['lines'][0]['id']}")->assertOk();
        $this->assertSame('DIBATALKAN', PpbDraft::find($extra['id'])->status);

        // edit one, then both into one PPB
        $this->putJson("/api/ppb-draft-lines/{$list[1]['id']}", ['qty' => 12])->assertOk()->assertJsonPath('data.qty', 12);
        $this->postJson('/api/ppb-draft-lines/submit', ['line_ids' => [$list[0]['id'], $list[1]['id']], 'no_ppb' => 'PPB/SW/010', 'tgl_ppb' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.lines', 2);
        $rows = Ppb::where('no_ppb', 'PPB/SW/010')->orderBy('id')->get();
        $this->assertSame([['ATK.0001', 3.0], ['ATK.0002', 12.0]], $rows->map(fn ($r) => [$r->kode_barang, (float) $r->kuantitas])->all());
        $this->assertStringContainsString($req['number'], $rows[0]->catatan_baris);
        $this->assertSame($rows[0]->id, DB::table('item_request_lines')->where('item_request_id', $req['id'])->value('ppb_id'));
        $this->assertSame(['DIAJUKAN', 'DIAJUKAN', 'DIBATALKAN'], PpbDraft::orderBy('id')->pluck('status')->all());
        $this->getJson('/api/ppb-draft-lines')->assertJsonPath('meta.total', 0);
        $this->postJson('/api/ppb-draft-lines/submit', ['line_ids' => [$list[0]['id']], 'no_ppb' => 'X', 'tgl_ppb' => now()->toDateString()])->assertStatus(422);
        $this->assertSame('MENUNGGU_PENGADAAN', ItemRequest::find($req['id'])->status);
    }

    public function test_one_po_number_may_hold_items_of_several_vendors(): void
    {
        [, $ppbId] = $this->requestToPpb(5, 'PPB/SW/009');
        $this->actingAsRole('direktur');
        $this->postJson("/api/ppb/{$ppbId}/approval", ['action' => 'APPROVE'])->assertOk();
        $entry = PurchaseOrder::sole();
        $kertas = $entry->items()->sole();
        // a second item of the same PO (e.g. another line of the PPB), bought somewhere else
        $tinta = $entry->items()->create(['description_raw' => 'TINTA', 'qty' => 2, 'unit_price' => 0, 'line_total' => 0]);
        [$a, $b] = [Vendor::factory()->create(['name' => 'TOKO KERTAS']), Vendor::factory()->create(['name' => 'TOKO TINTA'])];

        $this->actingAsRole('purchasing');
        // every line needs its vendor
        $this->putJson("/api/purchase-orders/{$entry->id}/complete", ['lines' => [['id' => $kertas->id, 'qty' => 5, 'unit_price' => 1000, 'vendor_id' => $a->id], ['id' => $tinta->id, 'qty' => 2, 'unit_price' => 500]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.1.vendor_id');
        $po = $this->putJson("/api/purchase-orders/{$entry->id}/complete", ['lines' => [
            ['id' => $kertas->id, 'qty' => 5, 'unit_price' => 1000, 'vendor_id' => $a->id], ['id' => $tinta->id, 'qty' => 2, 'unit_price' => 500, 'vendor_id' => $b->id],
        ]])->assertOk()->json('data');
        $this->assertSame(['TOKO KERTAS', 'TOKO TINTA'], array_column($po['items'], 'vendor_name'));
        $this->assertSame(['TOKO KERTAS', 'TOKO TINTA'], array_column($po['vendors'], 'name'));
        $this->assertSame(6000.0, (float) $po['total']);

        // the PO list: each line with its own vendor, and the vendor filter finds the line
        $rows = collect($this->getJson('/api/purchase-orders')->json('data'))->keyBy('id');
        $this->assertSame(['TOKO KERTAS', 'TOKO TINTA'], [$rows[$kertas->id]['vendor_name'], $rows[$tinta->id]['vendor_name']]);
        $this->getJson("/api/purchase-orders?vendor_id={$b->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tinta->id);
        // the PPB shows the vendor of its own line
        $this->actingAsRole('super_admin');
        $this->assertSame('TOKO KERTAS', collect($this->getJson('/api/ppb')->json('data'))->firstWhere('id', $ppbId)['vendor']);
    }
}
