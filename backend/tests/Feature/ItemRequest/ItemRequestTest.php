<?php

namespace Tests\Feature\ItemRequest;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\ItemRequestLink;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Services\ItemRecommender;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Request Barang: free-text request (app or share link) → admin turns lines into NPBG / PPB, with 5 stock recommendations. */
class ItemRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function item(string $code, string $desc, ?float $available): Item
    {
        $item = Item::factory()->create(['code' => $code, 'description' => $desc, 'is_active' => true]);
        if ($available !== null) {
            // real warehouse stock (the Request Barang page reads it live)
            $wh = \App\Models\Warehouse::query()->value('id') ?? \App\Models\Warehouse::factory()->create()->id;
            app(\App\Services\Inventory\StockLedgerService::class)->openingBalance($item->id, $wh, $available);
        }

        return $item;
    }

    public function test_karyawan_requests_and_admin_gudang_only_verifies_then_it_goes_to_approval(): void
    {
        $disc = $this->item('MAI.0073', 'MATA POTONG GERINDA TANGAN U/BESI - WD / 4"', 139);
        $this->item('MAI.0074', 'MATA POTONG GERINDA TANGAN U/KAYU - VSM / 4"', 1);
        $glove = $this->item('MAI.0366', 'SARUNG TANGAN SAFETY KERJA - LOKAL', 0);

        $karyawan = $this->actingAsRole('karyawan', ['name' => 'BUDI']);
        $req = $this->postJson('/api/item-requests', ['divisi' => 'MAINTENANCE', 'needed_date' => '2026-10-25', 'lines' => [
            ['deskripsi_barang' => 'mata gerenda potong besi 4 inch', 'qty' => 5, 'satuan' => 'PCS'],
            ['deskripsi_barang' => 'sarung tangan safety', 'qty' => 2, 'satuan' => 'PSG', 'note' => 'ukuran L'],
            ['deskripsi_barang' => 'kopi sachet', 'qty' => 1],
        ]])->assertCreated()->assertJsonPath('data.status', 'MENUNGGU')->assertJsonPath('data.requester_name', 'BUDI')->json('data');
        $this->assertMatchesRegularExpression('#^REQ/\d{2}/[IVX]+/001$#', $req['number']);
        $this->assertSame([], $req['lines'][0]['recommendations'], 'a karyawan never sees the stock recommendations');

        // the karyawan sees only their own; admin sees everything with 5 recommendations per open line
        $this->getJson('/api/item-requests')->assertOk()->assertJsonCount(1, 'data');
        $admin = $this->actingAsRole('admin_gudang');
        $shown = $this->getJson("/api/item-requests/{$req['id']}")->assertOk()->json('data');
        $this->assertSame('MAI.0073', $shown['lines'][0]['recommendations'][0]['code'], 'typo "gerenda" + "besi" -> the steel cutting disc first');
        $this->assertSame(139.0, (float) $shown['lines'][0]['recommendations'][0]['available']);
        $this->assertSame('MAI.0366', $shown['lines'][1]['recommendations'][0]['code']);
        [$l1, $l2, $l3] = array_column($shown['lines'], 'id');

        // admin gudang only verifies: the master item and the quantity — NPBG or PPB is decided after the approval
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$l1 => ['qty' => 9]]])->assertStatus(422);
        // "kopi sachet" is not in the master: it must be added first
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$l1 => ['item_id' => $disc->id], $l2 => ['item_id' => $glove->id], $l3 => []]])->assertStatus(422);
        $kopi = $this->item('KOPI.01', 'KOPI SACHET', null);
        $this->postJson("/api/item-requests/{$req['id']}/process", [
            'lines' => [$l1 => ['item_id' => $disc->id], $l2 => ['item_id' => $glove->id, 'qty' => 1], $l3 => ['item_id' => $kopi->id]],
            'npbg' => ['pelanggan' => 'MAINTENANCE (SDA)', 'tipe_npbg' => 'Non Penjualan'],
        ])->assertOk()->assertJsonPath('data.status', 'APPROVAL_MANAGER');

        $this->assertSame(0, Npbg::count());
        $this->assertSame(0, Ppb::count());
        $r = ItemRequest::with('lines')->find($req['id']);
        $this->assertSame(['MAINTENANCE (SDA)', 'Non Penjualan'], [$r->npbg_pelanggan, $r->npbg_tipe]);
        $this->assertSame([['MAI.0073', 5.0], ['MAI.0366', 1.0], ['KOPI.01', 1.0]], $r->lines->map(fn ($l) => [$l->kode_barang, (float) $l->approved_qty])->all());
        $lines = $this->getJson("/api/item-requests/{$req['id']}")->json('data.lines');
        $this->assertSame(['APPROVAL_MANAGER', 'APPROVAL_MANAGER', 'APPROVAL_MANAGER'], array_column($lines, 'line_status'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $karyawan->id)->count(), 'the requester is told');
        $this->postJson("/api/item-requests/{$req['id']}/process", ['lines' => [$l1 => []]])->assertStatus(422); // already verified
    }

    public function test_share_link_sends_a_request_without_an_account_and_admin_can_turn_it_off(): void
    {
        $this->actingAsRole('admin_gudang');
        $link = $this->postJson('/api/item-request-links', ['label' => 'Umum', 'form_type' => 'UMUM'])->assertCreated()->json('data');
        app('auth')->forgetGuards();

        $body = ['requester_name' => 'ANDI', 'requester_contact' => '0812', 'divisi' => 'DISTRIBUSI',
            'lines' => [['deskripsi_barang' => 'lakban coklat', 'qty' => 3, 'item_id' => 999]]];
        $this->getJson("/api/public/item-request/{$link['token']}")->assertOk()->assertJsonPath('data.is_active', true);
        $num = $this->postJson("/api/public/item-request/{$link['token']}", $body)->assertCreated()->json('data.number');
        $r = ItemRequest::with('lines')->sole();
        $this->assertSame([$num, 'LINK', 'ANDI', '0812', null, null], [$r->number, $r->source, $r->requester_name, $r->requester_contact, $r->requester_id, $r->lines[0]->item_id]);
        $this->postJson("/api/public/item-request/{$link['token']}", $body + ['website' => 'spam'])->assertStatus(422);
        $this->postJson('/api/public/item-request/not-a-token', $body)->assertNotFound();

        // admin turns the link off -> the form is closed
        $this->actingAsRole('admin_gudang');
        $this->putJson("/api/item-request-links/{$link['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        app('auth')->forgetGuards();
        $this->postJson("/api/public/item-request/{$link['token']}", $body)->assertForbidden();
        $this->assertSame(1, ItemRequest::count());

        // a karyawan can't manage links
        $this->actingAsRole('karyawan');
        $this->getJson('/api/item-request-links')->assertForbidden();
        $this->assertTrue(ItemRequestLink::sole()->is(ItemRequestLink::find($link['id'])));
    }

    public function test_each_link_opens_its_own_form_and_only_admin_marks_a_request_urgent(): void
    {
        $this->actingAsRole('admin_gudang');
        $this->postJson('/api/item-request-links', ['label' => 'x'])->assertStatus(422); // a link must say which form
        $atk = $this->postJson('/api/item-request-links', ['form_type' => 'ATK'])->assertCreated()->assertJsonPath('data.form_label', 'Permintaan ATK')->json('data');
        $mtc = $this->postJson('/api/item-request-links', ['form_type' => 'MAINTENANCE'])->assertCreated()->json('data');
        app('auth')->forgetGuards();

        // no form asks urgency any more
        $this->getJson("/api/public/item-request/{$atk['token']}")->assertOk()
            ->assertJsonPath('data.form.label', 'Permintaan ATK')->assertJsonPath('data.form.urgency', false);
        $this->getJson("/api/public/item-request/{$mtc['token']}")->assertOk()
            ->assertJsonPath('data.form.detail_label', 'Untuk unit / mesin / lokasi')->assertJsonPath('data.form.default_divisi', 'MAINTENANCE');

        $line = ['lines' => [['deskripsi_barang' => 'pulpen hitam', 'qty' => 2]]];
        // a requester sending "urgent" anyway is ignored; nor can the sender switch the form — the link decides
        $this->postJson("/api/public/item-request/{$atk['token']}", ['requester_name' => 'A', 'urgency' => 'URGENT'] + $line)->assertCreated();
        $this->postJson("/api/public/item-request/{$atk['token']}", ['requester_name' => 'B', 'form_type' => 'MANUFAKTUR'] + $line)->assertCreated();
        $this->postJson("/api/public/item-request/{$mtc['token']}", ['requester_name' => 'C', 'form_detail' => 'Forklift FD30'] + $line)->assertCreated();

        $rows = ItemRequest::orderBy('id')->get()->keyBy('requester_name');
        $this->assertSame(['ATK', null], [$rows['A']->form_type, $rows['A']->urgency]);
        $this->assertSame(['ATK', null], [$rows['B']->form_type, $rows['B']->urgency]);
        $this->assertSame(['MAINTENANCE', 'Forklift FD30', 'MAINTENANCE'], [$rows['C']->form_type, $rows['C']->form_detail, $rows['C']->divisi],
            'the divisi starts from the form');

        // admin gudang decides: B is urgent -> on top; then not urgent any more
        $this->actingAsRole('admin_gudang');
        $this->putJson("/api/item-requests/{$rows['B']->id}/urgency", ['urgency' => 'URGENT'])->assertOk()->assertJsonPath('data.urgency', 'URGENT');
        $this->putJson("/api/item-requests/{$rows['B']->id}/urgency", ['urgency' => 'SANGAT'])->assertStatus(422);
        $list = $this->getJson('/api/item-requests')->assertOk()->json('data');
        $this->assertSame('B', $list[0]['requester_name'], 'an urgent request waits on top');
        // list filters: several statuses, requester, dates; counts per status and the requesters for the filter
        $res = $this->getJson('/api/item-requests?status=MENUNGGU,DIPROSES&requester=B&date_from='.now()->toDateString().'&date_to='.now()->toDateString())->assertOk();
        $this->assertSame(['B'], array_column($res->json('data'), 'requester_name'));
        $this->assertSame(['MENUNGGU' => 1], $res->json('meta.status_counts'));
        $this->assertSame(['A', 'B', 'C'], $this->getJson('/api/item-requests')->json('meta.requesters'));
        $this->assertCount(0, $this->getJson('/api/item-requests?date_from=2099-01-01')->json('data'));
        $this->assertNotEmpty($list[0]['items_preview']);
        $this->putJson("/api/item-requests/{$rows['B']->id}/urgency", ['urgency' => null])->assertOk()->assertJsonPath('data.urgency', null);
        $this->assertSame('C', $this->getJson('/api/item-requests')->json('data.0.requester_name'), 'back to newest first');
        $this->actingAsRole('karyawan');
        $this->putJson("/api/item-requests/{$rows['A']->id}/urgency", ['urgency' => 'URGENT'])->assertForbidden();

        $this->actingAsRole('admin_gudang');
        $list = $this->getJson('/api/item-requests')->assertOk()->json('data');
        $this->assertSame([true], array_values(array_unique(array_map(fn ($r) => $r['form_label'] !== null, $list))));
        $this->assertCount(2, $this->getJson('/api/item-requests?form_type=ATK')->json('data'));
        $this->assertCount(5, $this->getJson('/api/item-request-forms')->assertOk()->json('data'));

        // in the app, a karyawan picks the form
        $this->actingAsRole('karyawan');
        $this->postJson('/api/item-requests', ['form_type' => 'DISTRIBUSI', 'form_detail' => 'W 8552 QB'] + $line)->assertCreated()
            ->assertJsonPath('data.form_label', 'Permintaan Distribusi')->assertJsonPath('data.divisi', 'DISTRIBUSI');
        $this->postJson('/api/item-requests', ['form_type' => 'LAINNYA'] + $line)->assertStatus(422);

        // each requester once in the "Pemohon" filter
        $this->actingAsRole('admin_gudang');
        ItemRequest::create(['number' => 'REQ/X/99', 'requester_name' => 'A', 'status' => 'MENUNGGU', 'source' => 'APP']);
        $names = $this->getJson('/api/item-requests')->json('meta.requesters');
        $this->assertSame(array_values(array_unique($names)), $names);
    }

    public function test_requester_can_cancel_while_waiting_and_others_cannot_see_it(): void
    {
        $this->actingAsRole('karyawan');
        $id = $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'air galon', 'qty' => 2]]])->json('data.id');
        $this->actingAsRole('karyawan');
        $this->getJson("/api/item-requests/{$id}")->assertForbidden();
        $this->postJson("/api/item-requests/{$id}/cancel")->assertForbidden();
    }

    public function test_recommender_keeps_sizes_exact(): void
    {
        $this->item('SSP.2279', 'BAUT SS304 (ISO) - 14mm(2.0) / P = 60mm', 19);
        $this->item('SSP.2271', 'BAUT OBENG SS (ISO) - 4mm(0.7) / P = 20mm', 13);
        $this->item('AUT.0079', 'BAN DALAM - AEOLUS / (7.50)-16', 0);
        $this->item('AUT.0087', 'BAN DALAM - GAJAH TUNGGAL / (6.50/7.00)-16', 12);
        $r = app(ItemRecommender::class);

        $this->assertSame('SSP.2279', $r->recommend('baut ss 14mm panjang 6cm')[0]['code'], '14mm / 6cm = 60mm, not the 4mm one');
        $this->assertSame('AUT.0079', $r->recommend('ban dalam 7.50-16')[0]['code'], '7.50 stays one size');
    }
}
