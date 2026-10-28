<?php

namespace Tests\Feature\Tracking;

use App\Models\Asset;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Site;
use App\Models\Vendor;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PHASE 8 — modul tracking (Lend/Borrow/STPP/Ban Luar/Maintenance/Manufaktur/Bekas). */
class TrackingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create(['code' => 'SIG-SDA']);
    }

    public function test_lend_create_then_partial_and_full_return(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $lend = $this->postJson('/api/lend', [
            'item_id' => $item->id, 'qty' => 10, 'purpose' => 'RELASI',
            'borrower_name' => 'CV Mitra', 'est_days' => 7,
        ])->assertCreated()->json('data');
        $this->assertSame('ON_LOAN', $lend['status']);
        $this->assertNotNull($lend['due_date']);

        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 4])
            ->assertOk()->assertJsonPath('data.status', 'PARTIAL_RETURN');
        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 6])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 1])->assertStatus(422);
    }

    public function test_borrow_create_then_return(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $borrow = $this->postJson('/api/borrow', [
            'description_raw' => 'Trafo las', 'qty' => 2, 'lender_name' => 'Bengkel CDO',
        ])->assertCreated()->json('data');

        $this->postJson("/api/borrow/{$borrow['id']}/return", ['qty' => 2])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
    }

    public function test_stpp_issue_withdraw_reissue(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();

        $stpp = $this->postJson('/api/stpp', [
            'serial_no' => 'SN-9001', 'item_id' => $item->id, 'description_raw' => 'Kunci moment',
            'holder_name_raw' => 'Budi',
        ])->assertCreated()->json('data');
        $this->assertSame('ACTIVE', $stpp['status']);

        $this->postJson("/api/stpp/{$stpp['id']}/withdraw", ['return_note' => 'rusak'])
            ->assertOk()->assertJsonPath('data.status', 'PASSIVE');

        $reissued = $this->postJson("/api/stpp/{$stpp['id']}/reissue", ['holder_name_raw' => 'Andi'])
            ->assertCreated()->json('data');
        $this->assertSame('ACTIVE', $reissued['status']);
        $this->assertSame('SN-9001', $reissued['serial_no']);
        $this->assertDatabaseCount('stpp_transactions', 2);
    }

    public function test_tyre_change_pending_then_close(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);

        $tc = $this->postJson('/api/tyre-changes', [
            'asset_id' => $asset->id, 'position' => 'FRONT_L',
            'new_tyre_desc' => 'GT Radial 750-16', 'new_serial_raw' => '2320611829',
        ])->assertCreated()->json('data');
        $this->assertSame('PENDING_RI', $tc['status']);
        $this->assertSame(1, $tc['change_seq']);

        $this->postJson("/api/tyre-changes/{$tc['id']}/close", [])
            ->assertOk()->assertJsonPath('data.status', 'CLEAR');

        // opening record -> langsung CLEAR
        $op = $this->postJson('/api/tyre-changes', [
            'asset_id' => $asset->id, 'position' => 'SPARE', 'is_opening' => true,
        ])->assertCreated()->json('data');
        $this->assertSame('CLEAR', $op['status']);
    }

    /** Status per the Excel formula: Permintaan → REQUESTED, NPBG → ON-GOING, NPBG + Foto Sesudah + Tgl Selesai → COMPLETED. */
    public function test_maintenance_order_sub_rollup_to_completed(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \App\Models\Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/25/VIII/009', 'tgl_npbg' => '2025-08-01',
            'kode_barang' => 'X.1', 'deskripsi_barang' => 'KAMPAS REM', 'kuantitas' => 1, 'satuan' => 'SET', 'pelanggan' => 'W 1 A']);
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);

        $order = $this->postJson('/api/maintenance-orders', [
            'asset_id' => $asset->id, 'site_id' => $this->site->id, 'problem_summary' => 'Rem blong',
        ])->assertCreated()->json('data');
        $this->assertSame('REQUESTED', $order['status']);

        $withSub = $this->postJson("/api/maintenance-orders/{$order['id']}/subs", [
            'problem_detail' => 'Ganti kampas rem', 'workshop_raw' => 'SIG', 'request_note' => 'KAMPAS REM 1 SET',
        ])->assertOk()->json('data');
        $this->assertSame('REQUESTED', $withSub['subs'][0]['status']);
        $this->assertSame('MK.'.implode('.', array_slice(explode('/', $order['number']), 1)).'_SUB-01_Before', $withSub['subs'][0]['helper_before']);
        $subId = $withSub['subs'][0]['id'];

        $this->putJson("/api/maintenance-subs/{$subId}", ['npbg_no' => 'NA/25/VIII/009'])
            ->assertOk()->assertJsonPath('data.subs.0.status', 'ON_GOING')->assertJsonPath('data.status', 'ON_GOING');
        // no Foto Sesudah yet → still ON-GOING, like the Excel
        $this->postJson("/api/maintenance-subs/{$subId}/complete", ['result_note' => 'selesai, normal', 'finish_date' => '2025-08-03'])
            ->assertOk()->assertJsonPath('data.subs.0.status', 'ON_GOING');
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=';
        $photo = $this->postJson('/api/tracking-photos', ['entity' => 'maintenance_sub', 'entity_id' => $subId, 'slot' => 'after', 'photo' => $png])
            ->assertCreated()->json('data');
        $this->getJson("/api/maintenance-orders/{$order['id']}")
            ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.subs.0.status', 'COMPLETED')
            ->assertJsonPath('data.subs.0.after_file_name', fn ($n) => str_ends_with($n, '_SUB-01_After'))
            ->assertJsonPath('data.subs.0.photos.after.id', $photo['id']);
        $this->get("/api/tracking-photos/{$photo['id']}")->assertOk();
        $this->getJson('/api/maintenance-lines')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'COMPLETED');

        // a photo slot the module does not have is refused; another module's permission is needed for its photos
        $this->postJson('/api/tracking-photos', ['entity' => 'maintenance_sub', 'entity_id' => $subId, 'slot' => 'xyz', 'photo' => $png])->assertStatus(422);
    }

    public function test_manufacturing_jasa_requires_vendor(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);

        $this->postJson('/api/manufacturing-orders', [
            'kind' => 'JASA', 'site_id' => $this->site->id, 'product_name' => 'Bubut as',
        ])->assertStatus(422);

        $vendor = Vendor::factory()->create();
        $this->postJson('/api/manufacturing-orders', [
            'kind' => 'JASA', 'site_id' => $this->site->id, 'vendor_id' => $vendor->id, 'product_name' => 'Bubut as',
        ])->assertCreated()->assertJsonPath('data.kind', 'JASA');
    }

    public function test_used_return_create_then_close(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create(['site_id' => $this->site->id]);

        $ur = $this->postJson('/api/used-returns', [
            'npbg_ref_raw' => 'NA/25/VIII/138',
            'items' => [
                ['item_id' => $item->id, 'qty' => 3, 'condition' => 'REUSABLE', 'into_stock' => true],
                ['description_raw' => 'Baut GI', 'qty' => -2, 'condition' => 'SCRAP'],
            ],
        ])->assertCreated()->json('data');
        $this->assertSame('PENDING', $ur['status']);
        $this->assertSame(2, $ur['items_count']);

        // close() must not just flip status — it should actually create+confirm
        // a Receiving (source_type USED_RETURN) from these lines and move stock
        // for the into_stock=true REUSABLE line, per docs/status-flow.md §8.
        $closed = $this->postJson("/api/used-returns/{$ur['id']}/close", ['warehouse_id' => $warehouse->id])
            ->assertOk()->assertJsonPath('data.status', 'CLEAR')->json('data');
        $this->assertNotNull($closed['ri_number'], 'closing must link a real Receiving number');

        $inv = Inventory::where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->first();
        $this->assertNotNull($inv, 'the REUSABLE/into_stock line must have produced a stock movement');
        $this->assertSame(3.0, (float) $inv->actual_qty);

        $this->postJson("/api/used-returns/{$ur['id']}/close", ['warehouse_id' => $warehouse->id])
            ->assertStatus(422);
    }

    public function test_karyawan_cannot_create_lend(): void
    {
        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->postJson('/api/lend', ['description_raw' => 'x', 'qty' => 1])->assertForbidden();
    }

    /** index + show tiap modul harus 200 (eager-load relasi tidak boleh salah nama). */
    public function test_all_tracking_index_and_show_load_cleanly(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $vendor = Vendor::factory()->create();

        $created = [
            'lend' => $this->postJson('/api/lend', ['item_id' => $item->id, 'qty' => 2, 'borrower_name' => 'X'])->json('data.id'),
            'borrow' => $this->postJson('/api/borrow', ['description_raw' => 'Trafo', 'qty' => 1, 'lender_name' => 'Y'])->json('data.id'),
            'stpp' => $this->postJson('/api/stpp', ['serial_no' => 'SN-1', 'item_id' => $item->id, 'description_raw' => 'Alat', 'holder_name_raw' => 'Z'])->json('data.id'),
            'tyre-changes' => $this->postJson('/api/tyre-changes', ['asset_id' => $asset->id, 'position' => 'FRONT_L'])->json('data.id'),
            'maintenance-orders' => $this->postJson('/api/maintenance-orders', ['asset_id' => $asset->id, 'site_id' => $this->site->id, 'problem_summary' => 'P'])->json('data.id'),
            'manufacturing-orders' => $this->postJson('/api/manufacturing-orders', ['kind' => 'JASA', 'site_id' => $this->site->id, 'vendor_id' => $vendor->id])->json('data.id'),
            'used-returns' => $this->postJson('/api/used-returns', ['items' => [['item_id' => $item->id, 'qty' => 1, 'condition' => 'USED']]])->json('data.id'),
        ];

        foreach ($created as $base => $id) {
            $this->getJson("/api/{$base}")->assertOk()->assertJsonStructure(['data']);
            $this->getJson("/api/{$base}/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        }
    }

    /** Semua modul tracking bisa diedit/dihapus admin selagi masih "open" — untuk re-entry data Excel. */
    public function test_lend_can_be_edited_and_deleted_while_open_only(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();
        $lend = $this->postJson('/api/lend', ['item_id' => $item->id, 'qty' => 5, 'borrower_name' => 'A'])->json('data');

        $this->putJson("/api/lend/{$lend['id']}", ['qty' => 8, 'borrower_name' => 'B'])
            ->assertOk()->assertJsonPath('data.qty', 8)->assertJsonPath('data.borrower_name', 'B');

        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 8]);
        // like STPP / the Excel a returned row can still be corrected inline — but not deleted
        $this->putJson("/api/lend/{$lend['id']}", ['borrower_name' => 'C'])
            ->assertOk()->assertJsonPath('data.borrower_name', 'C')->assertJsonPath('data.status', 'RETURNED');
        $this->deleteJson("/api/lend/{$lend['id']}")->assertStatus(422);

        $lend2 = $this->postJson('/api/lend', ['item_id' => $item->id, 'qty' => 3])->json('data');
        $this->deleteJson("/api/lend/{$lend2['id']}")->assertOk();
        $this->assertDatabaseMissing('lend_transactions', ['id' => $lend2['id']]);
    }

    public function test_borrow_can_be_edited_and_deleted_while_open_only(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $borrow = $this->postJson('/api/borrow', ['description_raw' => 'Trafo', 'qty' => 1, 'lender_name' => 'X'])->json('data');

        $this->putJson("/api/borrow/{$borrow['id']}", ['lender_name' => 'Y'])
            ->assertOk()->assertJsonPath('data.lender_name', 'Y');

        $this->postJson("/api/borrow/{$borrow['id']}/return", ['qty' => 1]);
        $this->putJson("/api/borrow/{$borrow['id']}", ['return_note' => 'ok'])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->deleteJson("/api/borrow/{$borrow['id']}")->assertStatus(422);
    }

    /** Typed straight into the table (like STPP): every Excel column, status by the Excel formula. */
    public function test_lend_and_borrow_rows_typed_inline_follow_the_excel_formula(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $lend = $this->postJson('/api/lend', ['description_raw' => 'Kunci', 'qty' => 1, 'requester_name' => 'Budi', 'out_doc_no' => null, 'est_days' => null])
            ->assertCreated()->json('data');
        $this->assertSame('INCOMPLETE', $lend['status']);
        $this->putJson("/api/lend/{$lend['id']}", ['est_days' => 30, 'out_date' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'ON_LOAN');
        $this->putJson("/api/lend/{$lend['id']}", ['return_date' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->putJson("/api/lend/{$lend['id']}", ['return_date' => null])
            ->assertOk()->assertJsonPath('data.status', 'ON_LOAN');

        $borrow = $this->postJson('/api/borrow', ['description_raw' => 'Trafo', 'qty' => 1, 'lender_name' => 'X', 'in_doc_no' => null])
            ->assertCreated()->json('data');
        $this->assertSame('INCOMPLETE', $borrow['status']);
        $this->deleteJson("/api/borrow/{$borrow['id']}")->assertOk();
    }

    public function test_manual_tyre_row_clears_when_the_ri_is_typed_and_reopens_when_cleared(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $tc = $this->postJson('/api/tyre-changes', ['nopol_raw' => 'de 1234 ab', 'new_serial_raw' => 'X1'])->assertCreated()->json('data');
        $this->assertSame('PENDING_RI', $tc['status']);
        $this->putJson("/api/tyre-changes/{$tc['id']}", ['in_date' => now()->toDateString()])->assertOk()->assertJsonPath('data.status', 'CLEAR');
        $this->putJson("/api/tyre-changes/{$tc['id']}", ['in_date' => null])->assertOk()->assertJsonPath('data.status', 'PENDING_RI');
    }

    public function test_stpp_can_be_edited_and_deleted_while_active_only(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $stpp = $this->postJson('/api/stpp', ['serial_no' => 'SN-9', 'description_raw' => 'Alat', 'holder_name_raw' => 'A'])->json('data');

        $this->putJson("/api/stpp/{$stpp['id']}", ['holder_name_raw' => 'B'])
            ->assertOk()->assertJsonPath('data.holder', 'B');

        $this->postJson("/api/stpp/{$stpp['id']}/withdraw", []);
        // like the Excel, a withdrawn row can still be corrected — but not deleted (it is history)
        $this->putJson("/api/stpp/{$stpp['id']}", ['holder_name_raw' => 'C'])->assertOk()->assertJsonPath('data.holder', 'C');
        $this->deleteJson("/api/stpp/{$stpp['id']}")->assertStatus(422);
    }

    public function test_tyre_change_can_be_edited_and_deleted_while_pending_only(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $tc = $this->postJson('/api/tyre-changes', ['asset_id' => $asset->id, 'position' => 'FRONT_L'])->json('data');

        $this->putJson("/api/tyre-changes/{$tc['id']}", ['reason' => 'aus'])
            ->assertOk()->assertJsonPath('data.reason', 'aus');

        $this->postJson("/api/tyre-changes/{$tc['id']}/close", []);
        // like the Excel a CLEAR row can still be corrected — but not deleted
        $this->putJson("/api/tyre-changes/{$tc['id']}", ['reason' => 'lain'])->assertOk()->assertJsonPath('data.status', 'CLEAR');
        $this->deleteJson("/api/tyre-changes/{$tc['id']}")->assertStatus(422);

        $tc2 = $this->postJson('/api/tyre-changes', ['asset_id' => $asset->id, 'position' => 'FRONT_R'])->json('data');
        $this->deleteJson("/api/tyre-changes/{$tc2['id']}")->assertOk();
        $this->assertDatabaseMissing('tyre_changes', ['id' => $tc2['id']]);
    }

    public function test_maintenance_order_and_sub_edit_delete_rules(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $asset = Asset::factory()->create(['site_id' => $this->site->id]);
        $order = $this->postJson('/api/maintenance-orders', [
            'asset_id' => $asset->id, 'site_id' => $this->site->id, 'problem_summary' => 'awal',
        ])->json('data');

        $this->putJson("/api/maintenance-orders/{$order['id']}", ['problem_summary' => 'diubah'])
            ->assertOk()->assertJsonPath('data.problem_summary', 'diubah');

        $sub = $this->postJson("/api/maintenance-orders/{$order['id']}/subs", ['problem_detail' => 'sub1'])
            ->json('data.subs.0');

        // order sudah punya sub -> tidak bisa dihapus langsung
        $this->deleteJson("/api/maintenance-orders/{$order['id']}")->assertStatus(422);

        // every Excel column stays editable
        $this->putJson("/api/maintenance-subs/{$sub['id']}", ['problem_detail' => 'sub1 revisi', 'nopol_raw' => 'w 1 a'])
            ->assertOk()->assertJsonPath('data.subs.0.problem_detail', 'sub1 revisi')->assertJsonPath('data.subs.0.nopol', 'W 1 A');

        $this->deleteJson("/api/maintenance-subs/{$sub['id']}")->assertOk();
        $this->deleteJson("/api/maintenance-orders/{$order['id']}")->assertOk();
    }

    public function test_manufacturing_order_and_sub_edit_delete_rules(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $order = $this->postJson('/api/manufacturing-orders', [
            'kind' => 'ASSEMBLY', 'site_id' => $this->site->id, 'product_name' => 'Manifold',
        ])->json('data');

        $this->putJson("/api/manufacturing-orders/{$order['id']}", ['product_name' => 'Manifold V2'])
            ->assertOk()->assertJsonPath('data.product_name', 'Manifold V2');

        $this->deleteJson("/api/manufacturing-orders/{$order['id']}")->assertOk();
        $this->assertDatabaseMissing('manufacturing_orders', ['id' => $order['id']]);
    }

    public function test_used_return_can_be_edited_and_deleted_while_pending_only(): void
    {
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $item = Item::factory()->create();
        $ur = $this->postJson('/api/used-returns', ['items' => [['item_id' => $item->id, 'qty' => 2, 'condition' => 'USED']]])
            ->json('data');

        $this->putJson("/api/used-returns/{$ur['id']}", ['note' => 'revisi'])
            ->assertOk()->assertJsonPath('data.note', 'revisi');

        $warehouse = Warehouse::factory()->create(['site_id' => $this->site->id]);
        $this->postJson("/api/used-returns/{$ur['id']}/close", ['warehouse_id' => $warehouse->id])->assertOk();
        $this->putJson("/api/used-returns/{$ur['id']}", ['note' => 'x'])->assertStatus(422);
        $this->deleteJson("/api/used-returns/{$ur['id']}")->assertStatus(422);
    }

    public function test_karyawan_cannot_edit_or_delete_tracking_rows(): void
    {
        $item = Item::factory()->create();
        $this->actingAsRole('admin_gudang', ['site_id' => $this->site->id]);
        $lend = $this->postJson('/api/lend', ['item_id' => $item->id, 'qty' => 1])->json('data');

        $this->actingAsRole('karyawan', ['site_id' => $this->site->id]);
        $this->putJson("/api/lend/{$lend['id']}", ['qty' => 2])->assertForbidden();
        $this->deleteJson("/api/lend/{$lend['id']}")->assertForbidden();
    }
}
