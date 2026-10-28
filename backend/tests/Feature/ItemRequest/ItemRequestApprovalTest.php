<?php

namespace Tests\Feature\ItemRequest;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\Npbg;
use App\Models\PpbDraft;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockwiseNet;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Request Barang → admin gudang verifies (item + qty, no NPBG/PPB choice) → Manager → Direktur (may change
 * qty / send back) → the system gives from the stock (NPBG, maybe partial) and buys the rest (Draft PPB) →
 * serah terima each time; later deliveries by Gudang (NPBG lanjutan) → Selesai.
 */
class ItemRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    /** 1×1 PNG */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['stockwise.stockwise_ledger_since' => now()->subDay()->toDateTimeString()]);
        $this->item = Item::factory()->create(['code' => 'ATK.0001', 'description' => 'KERTAS HVS A4 70 GSM', 'is_active' => true]);
        $this->warehouse = Warehouse::factory()->create();
    }

    private function stock(float $qty): void
    {
        app(StockLedgerService::class)->record(['type' => 'STOCK_IN', 'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'qty' => $qty]);
    }

    private function onHand(): float
    {
        return (float) DB::table('inventory')->where('item_id', $this->item->id)->sum('actual_qty');
    }

    /** held for prepared NPBG, until their serah terima */
    private function held(): float
    {
        return (float) DB::table('inventory')->where('item_id', $this->item->id)->sum('reserved_qty');
    }

    /** A request of $qty that admin gudang verified (the master item, proposed qty) → waiting for the Manager. */
    private function verifiedRequest(float $qty = 10, ?float $proposed = null): array
    {
        $karyawan = $this->actingAsRole('karyawan', ['name' => 'SITI']);
        $req = $this->postJson('/api/item-requests', ['divisi' => 'HRD', 'lines' => [['deskripsi_barang' => 'kertas hvs a4', 'qty' => $qty, 'satuan' => 'RIM']]])
            ->assertCreated()->json('data');
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/item-requests/{$req['id']}/process", [
            'lines' => [$req['lines'][0]['id'] => ['item_id' => $this->item->id, 'qty' => $proposed]],
            'npbg' => ['pelanggan' => 'HRD'],
        ])->assertOk()->assertJsonPath('data.status', 'APPROVAL_MANAGER')->assertJsonPath('data.status_label', 'Persetujuan Manajer');
        $this->assertSame(0, Npbg::count(), 'nothing is issued before the approval');

        return [$req['id'], $karyawan, $req['lines'][0]['id']];
    }

    private function approveBoth(int $id): array
    {
        $this->actingAsRole('manager', ['name' => 'PAK MANAGER']);
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.status', 'APPROVAL_DIREKTUR');
        $this->actingAsRole('direktur');

        return $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertOk()->json('data');
    }

    /** Gudang fixes the quantities ($handed: less than prepared) → SIAP, then the serah terima. */
    private function handover(int $id, int $fulfillmentId, array $handed = []): array
    {
        // one step: the quantities really handed over go with the serah terima
        return $this->postJson("/api/item-requests/{$id}/fulfillments/{$fulfillmentId}/handover",
            ['photo' => self::PNG, 'signature' => self::PNG, 'received_by' => 'Siti', 'handed' => $handed, 'reasons' => array_map(fn () => 'uji kurang', $handed)])->assertOk()->json('data');
    }

    public function test_enough_stock_the_system_makes_one_npbg_of_all(): void
    {
        $this->stock(20);
        [$id, $karyawan, $lineId] = $this->verifiedRequest(10, 6);

        $this->actingAsRole('karyawan');
        $this->getJson('/api/approvals')->assertForbidden();
        $this->actingAsRole('manager');
        $shown = $this->getJson("/api/item-requests/{$id}")->assertOk()->assertJsonPath('data.can_approve', true)->json('data');
        $this->assertSame(['done', 'done', 'current', 'todo', 'todo'], array_column($shown['timeline'], 'state'));
        $this->assertSame([10.0, 6.0], [(float) $shown['lines'][0]['qty'], (float) $shown['lines'][0]['approved_qty']]);
        // the approver does not change quantities (admin revises them after "Kembalikan")
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE', 'quantities' => [$lineId => 5]])->assertStatus(422)->assertJsonValidationErrors('quantities');

        $done = $this->approveBoth($id);
        // approved → NPBG of 6 from stock; Gudang prepares and hands it over in one step
        $this->assertSame(['DISIAPKAN', 'DISIAPKAN', 'Siapkan & serah terima gudang'], [$done['status'], $done['lines'][0]['line_status'], $done['status_label']]);
        // the stock (Master Produk) stays until the serah terima: the 6 are only held for this request
        $this->assertSame([20.0, 6.0], [$this->onHand(), $this->held()], 'the stock leaves with the serah terima, not before');
        $this->actingAsRole('lapangan_gudang');
        $this->getJson('/api/approvals')->assertJsonPath('data.0.lines.0.on_hand', 20)->assertJsonPath('data.0.lines.0.available', 14);
        $this->assertSame(['HRD', 6.0], [Npbg::sole()->pelanggan, (float) Npbg::sole()->kuantitas]);
        $this->assertSame(0, PpbDraft::count());

        $this->actingAsRole('lapangan_gudang', ['name' => 'JOKO']);
        // one step at Gudang: the prepared NPBG is handed over straight away (quantities fixed in the serah terima)
        $ready = $this->getJson("/api/item-requests/{$id}")->assertOk()->assertJsonPath('data.can_handover', true)->json('data');
        $this->assertArrayNotHasKey('can_confirm', $ready);
        $this->actingAsRole('lapangan_gudang');
        $this->getJson('/api/approvals')->assertJsonPath('data.0.can_handover', true)->assertJsonPath('data.0.fulfillments.0.lines.0.qty', 6);
        $this->postJson("/api/item-requests/{$id}/fulfillments/{$ready['fulfillments'][0]['id']}/handover", ['photo' => self::PNG, 'signature' => self::PNG, 'received_by' => 'Siti',
            'handed' => [$ready['fulfillments'][0]['lines'][0]['id'] => 0]])->assertStatus(422); // nothing handed over
        $this->postJson("/api/item-requests/{$id}/fulfillments/{$ready['fulfillments'][0]['id']}/handover", ['photo' => self::PNG, 'received_by' => 'Siti'])->assertStatus(422);
        $done = $this->handover($id, $ready['fulfillments'][0]['id']);
        $this->assertSame(['SELESAI', 'SELESAI', 6.0, 0.0], [$done['status'], $done['lines'][0]['line_status'], (float) $done['lines'][0]['fulfilled_qty'], (float) $done['lines'][0]['remaining']]);
        // the list: approved 6 / handed over 6
        $this->actingAsRole('admin_gudang');
        $this->assertEquals(['qty' => 6, 'done' => 6], array_intersect_key($this->getJson('/api/item-requests')->json('data.0.progress.0'), ['qty' => 0, 'done' => 0]));
        $this->actingAsRole('lapangan_gudang');
        $this->assertSame([14.0, 0.0], [$this->onHand(), $this->held()], 'handed over: out of the stock now');
        $this->assertSame(['done'], array_values(array_unique(array_column($done['timeline'], 'state'))));
        $this->get("/api/item-requests/{$id}/fulfillments/{$done['fulfillments'][0]['id']}/file/signature")->assertOk();

        $this->actingAs($karyawan, 'sanctum');
        $this->get("/api/item-requests/{$id}/fulfillments/{$done['fulfillments'][0]['id']}/file/handover")->assertOk();
        $this->actingAsRole('karyawan');
        $this->get("/api/item-requests/{$id}/fulfillments/{$done['fulfillments'][0]['id']}/file/handover")->assertForbidden();
    }

    public function test_the_validated_master_name_is_used_to_the_end(): void
    {
        $this->stock(20);
        $karyawan = $this->actingAsRole('karyawan', ['name' => 'SITI']);
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'kertas putih buat print', 'qty' => 4, 'satuan' => 'lembar']]])->json('data');
        $lineId = $req['lines'][0]['id'];
        $this->actingAsRole('admin_gudang');
        // picking the master item: the line takes its name, code and unit; the requester's words stay aside
        $picked = $this->putJson("/api/item-requests/{$req['id']}/lines/{$lineId}/match", ['item_id' => $this->item->id])->assertOk()->json('data.lines.0');
        $this->assertSame(['KERTAS HVS A4 70 GSM', 'ATK.0001', 'kertas putih buat print'], [$picked['deskripsi_barang'], $picked['kode_barang'], $picked['deskripsi_peminta']]);
        $this->assertNotEmpty($picked['recommendations'], 'recommendations still come from what was asked');
        // only an active Master Produk item goes to approval
        $this->item->update(['is_active' => false]);
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => ['item_id' => $this->item->id, 'qty' => 3]]])->assertStatus(422)->assertJsonValidationErrors("lines.{$lineId}.item_id");
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => ['item_id' => 999999, 'qty' => 3]]])->assertStatus(422)->assertJsonValidationErrors("lines.{$lineId}.item_id");
        $this->item->update(['is_active' => true]);
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => ['item_id' => $this->item->id, 'qty' => 3]]])->assertOk();
        $done = $this->approveBoth($id = $req['id'], [$lineId => 3]);
        $this->actingAsRole('lapangan_gudang');
        $f = $this->getJson("/api/item-requests/{$id}")->json('data.fulfillments.0');
        $done = $this->handover($id, $f['id']);
        $notes = collect($done['approval_log'])->pluck('note')->filter()->implode(' | ');
        $this->assertStringContainsString('KERTAS HVS A4 70 GSM', $notes);
        $this->assertStringNotContainsString('kertas putih', $notes);
        $this->assertSame('KERTAS HVS A4 70 GSM', Npbg::sole()->deskripsi_barang);
        $this->assertStringNotContainsString('kertas putih', (string) Npbg::sole()->deskripsi);
        // the requester follows it under the master name too
        $this->actingAs($karyawan, 'sanctum');
        $token = $this->getJson("/api/item-requests/{$id}")->json('data.track_token');
        $this->getJson("/api/public/request-status?tokens={$token}")->assertJsonPath('data.0.lines.0.deskripsi_barang', 'KERTAS HVS A4 70 GSM');
    }

    public function test_lines_matched_before_are_converted_and_only_system_notes_renamed(): void
    {
        [$id, , $lineId] = $this->verifiedRequest(5);
        // as a line matched before the change: the requester's text on it, a system note and a person's note naming it
        DB::table('item_request_lines')->where('id', $lineId)->update(['deskripsi_barang' => 'kertas hvs a4', 'deskripsi_peminta' => null]);
        DB::table('item_request_approvals')->insert([
            ['item_request_id' => $id, 'stage' => 'GUDANG', 'action' => 'FULFILL', 'note' => 'NPBG X (#1): kertas hvs a4 3 RIM', 'user_name' => 'Sistem', 'created_at' => now()],
            ['item_request_id' => $id, 'stage' => 'MANAGER', 'action' => 'APPROVE', 'note' => 'kertas hvs a4 yang bagus ya', 'user_name' => 'PAK', 'created_at' => now()],
        ]);
        (require database_path('migrations/2026_10_26_090000_item_request_lines_validated_name.php'))->up();
        $line = DB::table('item_request_lines')->find($lineId);
        $this->assertSame(['KERTAS HVS A4 70 GSM', 'kertas hvs a4'], [$line->deskripsi_barang, $line->deskripsi_peminta]);
        $notes = DB::table('item_request_approvals')->where('item_request_id', $id)->pluck('note')->filter()->values()->all();
        $this->assertContains('NPBG X (#1): KERTAS HVS A4 70 GSM 3 RIM', $notes);
        $this->assertContains('kertas hvs a4 yang bagus ya', $notes);
    }

    public function test_gudang_hands_over_less_and_closes_the_rest(): void
    {
        $this->stock(20);
        [$id, , $lineId] = $this->verifiedRequest(6);
        $this->approveBoth($id);
        $this->actingAsRole('lapangan_gudang', ['name' => 'JOKO']);
        $f = $this->getJson("/api/item-requests/{$id}")->json('data.fulfillments.0');
        $fl = $f['lines'][0]['id'];

        // less than the approved NPBG while the stock was there: a reason is required
        $this->postJson("/api/item-requests/{$id}/fulfillments/{$f['id']}/handover", ['photo' => self::PNG, 'signature' => self::PNG, 'received_by' => 'Siti',
            'handed' => [$fl => 4], 'drop' => [$fl]])->assertStatus(422)->assertJsonValidationErrors("reasons.{$fl}");
        // 4 of the 6 prepared handed over, the other 2 not needed any more: back to stock, approved 4, done
        $done = $this->postJson("/api/item-requests/{$id}/fulfillments/{$f['id']}/handover", ['photo' => self::PNG, 'signature' => self::PNG, 'received_by' => 'Siti',
            'handed' => [$fl => 4], 'drop' => [$fl], 'reasons' => [$fl => 'Peminta cukup 4']])->assertOk()->json('data');
        $this->assertSame(['SELESAI', 4.0, 4.0, 0.0], [$done['status'], (float) $done['lines'][0]['approved_qty'], (float) $done['lines'][0]['fulfilled_qty'], (float) $done['lines'][0]['remaining']]);
        $this->assertSame(16.0, $this->onHand());
        $this->assertSame(4.0, (float) Npbg::sole()->kuantitas);
        $log = collect($done['approval_log'])->firstWhere('stage', 'SERAH_TERIMA')['note'];
        $this->assertStringContainsString('diserahkan ke Siti', $log);
        $this->assertStringContainsString('4', $log);
        $this->assertStringContainsString('2 tetap di stok, sisa ditutup; alasan: Peminta cukup 4', $log);
    }

    public function test_gudang_hands_over_less_and_the_rest_stays_owed(): void
    {
        $this->stock(20);
        // 1 of 3 handed over, the rest still owed (Gudang prepares it again later)
        [$id2] = $this->verifiedRequest(3);
        $this->approveBoth($id2);
        $this->actingAsRole('lapangan_gudang');
        $f2 = $this->getJson("/api/item-requests/{$id2}")->json('data.fulfillments.0');
        $part = $this->postJson("/api/item-requests/{$id2}/fulfillments/{$f2['id']}/handover", ['photo' => self::PNG, 'signature' => self::PNG, 'received_by' => 'Budi',
            'handed' => [$f2['lines'][0]['id'] => 1], 'reasons' => [$f2['lines'][0]['id'] => 'Barang fisik rusak']])->assertOk()->json('data');
        $this->assertSame([3.0, 1.0, 2.0], [(float) $part['lines'][0]['approved_qty'], (float) $part['lines'][0]['fulfilled_qty'], (float) $part['lines'][0]['remaining']]);
        $this->assertSame('DISIAPKAN', $part['status'], 'stock is there for the 2 still owed');
        $this->assertStringContainsString('sisa tetap ditunggu', collect($part['approval_log'])->firstWhere('stage', 'SERAH_TERIMA')['note']);
    }

    public function test_short_stock_partial_npbg_and_the_rest_to_draft_ppb_then_npbg_lanjutan(): void
    {
        $this->stock(4);
        [$id, , $lineId] = $this->verifiedRequest(10);
        $ready = $this->approveBoth($id);
        // 4 from stock (NPBG), 6 to buy (Draft PPB)
        $this->assertSame('DISIAPKAN', $ready['status']);
        $this->assertSame(4.0, (float) Npbg::sole()->kuantitas);
        $this->assertEquals(6, PpbDraft::where('item_request_id', $id)->sole()->lines()->sole()->qty);
        $this->assertSame([4.0, 4.0], [$this->onHand(), $this->held()], 'held, still in stock');
        // the Item table: 4 given from stock (NPBG), 6 bought (PPB, still a draft)
        $this->assertSame([4.0, 6.0, 'Draft PPB'], [(float) $ready['lines'][0]['npbg']['qty'], (float) $ready['lines'][0]['ppb']['qty'], $ready['lines'][0]['ppb']['stage']]);
        // clear labels: part from stock (NPBG partial), the rest being bought
        $this->assertSame('Siapkan & serah terima gudang · NPBG partial & menunggu pengadaan', $ready['status_label']);
        $this->assertSame('Siapkan & serah terima gudang · NPBG partial & menunggu pengadaan', $ready['lines'][0]['line_status_label']);

        $this->actingAsRole('lapangan_gudang');
        // only 3 really handed over: 1 back to stock, the NPBG says 3
        $after = $this->handover($id, $ready['fulfillments'][0]['id'], [$ready['fulfillments'][0]['lines'][0]['id'] => 3]);
        $this->assertSame(1.0, $this->onHand());
        $this->assertEquals(3, Npbg::sole()->kuantitas);
        $this->assertSame(['DISIAPKAN', 3.0, 7.0], [$after['status'], (float) $after['lines'][0]['fulfilled_qty'], (float) $after['lines'][0]['remaining']]);
        $f2 = $this->postJson("/api/item-requests/{$id}/fulfillments", ['lines' => [['line_id' => $lineId, 'qty' => 2]]])->assertStatus(422); // only 1 in stock
        $f2 = $this->postJson("/api/item-requests/{$id}/fulfillments", ['lines' => [['line_id' => $lineId, 'qty' => 1]]])->assertOk()->json('data.fulfillments.1');
        $after = $this->handover($id, $f2['id']);
        $this->assertSame(['MENUNGGU_PENGADAAN', 'MENUNGGU_PENGADAAN'], [$after['status'], $after['lines'][0]['line_status']]);
        $this->assertSame('NPBG partial & menunggu pengadaan', $after['status_label']);

        // a manual RI brings goods: given at once (NPBG lanjutan), ready for the serah terima
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/ri', ['divisi' => 'ATK', 'kode_barang' => 'ATK.0001', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => 10, 'photos' => [self::PNG]])->assertCreated();
        $this->assertSame([10.0, 6.0, 'DISIAPKAN', 3], [$this->onHand(), $this->held(), ItemRequest::find($id)->status, Npbg::count()]);
        $this->assertSame(4.0, (float) DB::table('inventory_snapshots')->where('item_id', $this->item->id)->value('available'), 'the stock shown follows at once');

        $this->actingAsRole('lapangan_gudang');
        $shown = $this->getJson("/api/item-requests/{$id}")->assertOk()->json('data');
        $this->assertSame(4.0, (float) $shown['lines'][0]['available'], 'the Stok column of the Item table is live');
        $f3 = $shown['fulfillments'][2];
        $this->assertEquals(6, $f3['lines'][0]['qty']);
        $this->assertSame(3, $f3['seq']);
        $this->assertStringContainsString('NPBG lanjutan #3', Npbg::orderByDesc('id')->first()->keterangan);
        $done = $this->handover($id, $f3['id']);
        $this->assertSame(['SELESAI', 10.0], [$done['status'], (float) $done['lines'][0]['fulfilled_qty']]);
        $this->assertSame([$this->item->id => 0.0], StockwiseNet::byItem());
    }

    public function test_no_stock_everything_to_draft_ppb_and_the_rest_can_be_closed(): void
    {
        [$id] = $this->verifiedRequest(5);
        $done = $this->approveBoth($id);
        $this->assertSame(['MENUNGGU_PENGADAAN', 0], [$done['status'], Npbg::count()]);
        $this->assertSame('Menunggu pengadaan (PPB)', $done['status_label']);
        $this->assertEquals(5, PpbDraft::where('item_request_id', $id)->sole()->lines()->sole()->qty);
        $this->assertSame(['done', 'done', 'done', 'done', 'current'], array_column($done['timeline'], 'state'));

        $this->actingAsRole('lapangan_gudang');
        $this->postJson("/api/item-requests/{$id}/close", ['reason' => ''])->assertStatus(422);
        $done = $this->postJson("/api/item-requests/{$id}/close", ['reason' => 'Tidak dibutuhkan lagi'])->assertOk()->json('data');
        $this->assertSame(['SELESAI', 'DITUTUP', 'Tidak dibutuhkan lagi'], [$done['status'], $done['lines'][0]['line_status'], $done['lines'][0]['closed_reason']]);
        $this->assertSame('DIBATALKAN', PpbDraft::where('item_request_id', $id)->value('status'), 'its unsubmitted draft is not bought any more');
    }

    public function test_not_in_the_master_it_is_added_first_then_a_service_is_bought(): void
    {
        $this->actingAsRole('karyawan');
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'service ac ruang meeting', 'qty' => 1]]])->json('data');
        $lineId = $req['lines'][0]['id'];
        $this->actingAsRole('admin_gudang');
        // not in the master: cannot be verified
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => ['item_id' => null]]])
            ->assertStatus(422)->assertJsonValidationErrors("lines.{$lineId}.item_id");
        // added to Master Produk as Jasa (a new Barang starts at stock 0 — checked below)
        $jasa = $this->postJson('/api/items', ['code' => 'JS.0001', 'description' => 'SERVICE AC', 'product_kind' => 'JASA'])->assertCreated()->json('data');
        $barang = $this->postJson('/api/items', ['code' => 'BR.0001', 'description' => 'FILTER AC', 'product_kind' => 'BARANG'])->assertCreated()->json('data');
        $this->assertSame([0, 1], [DB::table('inventory')->where('item_id', $jasa['id'])->count(), DB::table('inventory')->where('item_id', $barang['id'])->count()]);
        $this->assertSame(0.0, (float) DB::table('inventory')->where('item_id', $barang['id'])->value('actual_qty'));

        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => ['item_id' => $jasa['id']]]])->assertOk()->assertJsonPath('data.status', 'APPROVAL_MANAGER');
        $this->assertSame('MENUNGGU_PENGADAAN', $this->approveBoth($req['id'])['status']);
        $this->assertSame([0, 'SERVICE AC'], [Npbg::count(), PpbDraft::sole()->lines()->sole()->deskripsi_barang]);
    }

    public function test_manager_and_direktur_cannot_change_quantities(): void
    {
        $this->stock(30);
        [$id, , $lineId] = $this->verifiedRequest(10, 8);
        $this->actingAsRole('manager');
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE', 'note' => 'x', 'quantities' => [$lineId => 15]])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertOk();
        $this->actingAsRole('direktur');
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE', 'quantities' => [$lineId => 15]])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $done = $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertOk()->json('data');
        $this->assertEquals(8, $done['lines'][0]['approved_qty'], 'what admin verified');
    }

    public function test_the_approver_note_and_quantity_are_told_to_everyone(): void
    {
        $this->stock(30);
        [$id, $karyawan, $lineId] = $this->verifiedRequest(10);
        $admin = \App\Models\User::whereHas('roles', fn ($q) => $q->where('slug', 'admin_gudang'))->latest('id')->first();
        $this->actingAsRole('manager', ['name' => 'PAK MANAGER']);
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE', 'note' => 'Cukup 7 dulu, sisanya bulan depan'])->assertOk();
        $shown = $this->getJson("/api/item-requests/{$id}")->json('data');
        $this->assertStringContainsString('Cukup 7 dulu', $shown['timeline'][2]['note']);
        // admin gudang and the requester are told
        foreach ([$admin->id, $karyawan->id] as $uid) {
            $this->assertTrue(DB::table('notifications')->where('notifiable_id', $uid)->where('data', 'like', '%Cukup 7 dulu%')->exists());
        }
    }

    public function test_the_picked_master_item_is_saved_at_once(): void
    {
        $this->stock(7);
        $this->actingAsRole('karyawan');
        $req = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'kertas', 'qty' => 2]]])->json('data');
        $lineId = $req['lines'][0]['id'];
        $this->putJson("/api/item-requests/{$req['id']}/lines/{$lineId}/match", ['item_id' => $this->item->id])->assertForbidden();

        $this->actingAsRole('admin_gudang');
        $shown = $this->putJson("/api/item-requests/{$req['id']}/lines/{$lineId}/match", ['item_id' => $this->item->id])->assertOk()->json('data');
        // the Item table shows the master item and its stock; still waiting for verification
        $this->assertSame(['KERTAS HVS A4 70 GSM', 'ATK.0001', null, 'MENUNGGU'], [$shown['lines'][0]['item_description'], $shown['lines'][0]['kode_barang'], $shown['lines'][0]['decision'], $shown['status']]);
        // verified without naming the item again: the saved one is used
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$lineId => []]])->assertOk()->assertJsonPath('data.status', 'APPROVAL_MANAGER');
        $this->putJson("/api/item-requests/{$req['id']}/lines/{$lineId}/match", ['item_id' => $this->item->id])->assertStatus(422);
    }

    public function test_return_for_revision_then_the_admin_verifies_again(): void
    {
        [$id, , $lineId] = $this->verifiedRequest(10);
        $this->actingAsRole('manager');
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'RETURN'])->assertStatus(422); // needs a reason
        $back = $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'RETURN', 'note' => 'Cek ulang jumlahnya'])->assertOk()
            ->assertJsonPath('data.status', 'PERLU_PERBAIKAN')->json('data');
        $this->assertNull($back['lines'][0]['decision']);
        $this->assertSame('Cek ulang jumlahnya', $back['timeline'][1]['note']);

        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/item-requests/{$id}/process", ['lines' => [$lineId => ['item_id' => $this->item->id, 'qty' => 12]]])->assertStatus(422); // more than asked
        $again = $this->postJson("/api/item-requests/{$id}/process", ['lines' => [$lineId => ['item_id' => $this->item->id, 'qty' => 5]]])
            ->assertOk()->assertJsonPath('data.status', 'APPROVAL_MANAGER')->json('data');
        $this->assertEquals(5, $again['lines'][0]['approved_qty']);
    }

    public function test_the_direktur_can_approve_over_the_managers_head(): void
    {
        $this->stock(20);
        $direktur = $this->actingAsRole('direktur', ['name' => 'BU DIREKTUR']);
        $manager = $this->actingAsRole('manager', ['name' => 'PAK MANAGER']);
        [$id] = $this->verifiedRequest();
        // at the Manager step the Direktur is told too: they may approve it straight away
        $this->assertContains('Request masuk (menunggu Manajer)', $direktur->notifications()->get()->pluck('data.title')->all());
        $this->actingAs($direktur, 'sanctum');
        $row = $this->getJson('/api/approvals')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame([true, 'DIREKTUR'], [$row['can_approve'], $row['approve_as']]);

        $done = $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertOk()->json('data');
        $this->assertSame('DISIAPKAN', $done['status']);
        $log = collect($done['approval_log'])->reverse()->values();
        $this->assertSame([['ADMIN', 'VALIDASI'], ['MANAGER', 'APPROVE'], ['DIREKTUR', 'APPROVE'], ['GUDANG', 'FULFILL']], $log->map(fn ($a) => [$a['stage'], $a['action']])->all());
        $this->assertStringContainsString('bypass', $log[1]['note']);
        $this->assertSame('Sistem', $log[3]['user_name']);
        $this->assertSame(['done', 'done', 'done', 'done', 'current'], array_column($done['timeline'], 'state'));
        // clear in the flow: the Manager step was approved automatically, and the Manager knows it
        $this->assertSame(['Persetujuan Manajer (otomatis, bypass Direktur)', 'bypass Direktur'], [$done['timeline'][2]['label'], $done['timeline'][2]['by']]);
        $this->assertStringContainsString('Direktur BU DIREKTUR langsung approve', $log[1]['note']);
        $this->assertContains("{$done['number']} disetujui langsung oleh Direktur", $manager->notifications()->get()->pluck('data.title')->all());

        $this->actingAs($manager, 'sanctum');
        $this->getJson('/api/approvals')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertStatus(422);
    }

    public function test_a_rejection_needs_a_reason_and_ends_the_request(): void
    {
        [$id] = $this->verifiedRequest();
        $this->actingAsRole('manager');
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'REJECT'])->assertStatus(422);
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'REJECT', 'note' => 'Stok untuk proyek'])->assertOk()
            ->assertJsonPath('data.status', 'DITOLAK');
        $timeline = $this->getJson("/api/item-requests/{$id}")->json('data.timeline');
        $this->assertSame(['done', 'done', 'rejected', 'skipped', 'skipped'], array_column($timeline, 'state'));
        $this->assertSame('Stok untuk proyek', $timeline[2]['note']);
        $this->postJson("/api/item-requests/{$id}/approval", ['action' => 'APPROVE'])->assertStatus(422);
        $this->assertSame([0, 0], [Npbg::count(), PpbDraft::count()]);
    }

    public function test_requester_checks_status_without_an_account(): void
    {
        $this->actingAsRole('admin_gudang');
        $link = $this->postJson('/api/item-request-links', ['label' => 'Umum', 'form_type' => 'ATK'])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();

        $sent = $this->postJson("/api/public/item-request/{$link['token']}", ['requester_name' => 'Rina Ayu', 'lines' => [['deskripsi_barang' => 'pulpen', 'qty' => 3]]])
            ->assertCreated()->json('data');
        $rows = $this->getJson('/api/public/request-status?tokens='.$sent['track_token'].',bogus!,'.str_repeat('x', 32))->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame([$sent['number'], 'MENUNGGU', 'Menunggu admin', 'pulpen'], [$rows[0]['number'], $rows[0]['status'], $rows[0]['status_label'], $rows[0]['lines'][0]['deskripsi_barang']]);
        $this->assertSame(['done', 'current', 'todo', 'todo', 'todo'], array_column($rows[0]['timeline'], 'state'));
        $this->assertArrayNotHasKey('requester_contact', $rows[0]);

        $this->postJson('/api/public/request-status/lookup', ['name' => '  rina   AYU '])->assertOk()->assertJsonPath('data.tokens', [$sent['track_token']]);
        $this->postJson('/api/public/request-status/lookup', ['name' => 'Rin'])->assertNotFound();
    }
}
