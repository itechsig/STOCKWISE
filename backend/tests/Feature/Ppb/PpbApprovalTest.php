<?php

namespace Tests\Feature\Ppb;

use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PpbApprovalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every PPB from the go-live day must be approved before it goes to PO; the log keeps every step. */
class PpbApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function ppb(string $no, string $date, int $seq = 0): Ppb
    {
        return Ppb::create(['accurate_reqid' => crc32($no), 'accurate_seq' => $seq, 'no_ppb' => $no, 'tgl_ppb' => $date, 'kode_barang' => 'OFN.0001',
            'deskripsi_barang' => "BARANG {$seq}", 'kuantitas' => 1, 'satuan' => 'PCS', 'status' => 'OPEN', 'qty_dipesan' => 0, 'qty_diterima' => 0, 'peminta' => 'BUDI']);
    }

    private function poFor(Ppb $ppb): void
    {
        $po = PurchaseOrder::factory()->create(['number' => 'BL/D/26/X/900', 'status' => 'SENT']);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'accurate_ppb_id' => $ppb->id, 'description_raw' => 'X', 'qty' => 1, 'qty_received' => 0]);
    }

    public function test_new_ppb_waits_for_approval_and_approved_lines_wait_in_siap_po(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $new1 = $this->ppb('PPB/NA/26/X/050', '2026-10-21', 0);
        $this->ppb('PPB/NA/26/X/050', '2026-10-21', 1);
        $old = $this->ppb('PPB/NA/26/X/001', '2026-10-01');

        $svc = app(PpbApprovalService::class);
        $svc->scan();
        $svc->scan(); // twice: no double log / notification
        $this->assertSame(1, DB::table('ppb_approvals')->where(['no_ppb' => 'PPB/NA/26/X/050', 'action' => 'DIAJUKAN'])->count());
        $this->assertSame(0, DB::table('ppb_approvals')->where('no_ppb', 'PPB/NA/26/X/001')->count(), 'PPBs before go-live need no approval');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $admin->id)->count());

        $rows = collect($this->getJson('/api/ppb')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('MENUNGGU', $rows[$new1->id]['approval']['status']);
        $this->assertSame('TIDAK_PERLU', $rows[$old->id]['approval']['status']);
        $this->assertCount(2, $this->getJson('/api/ppb?approval=MENUNGGU')->json('data'));
        $this->assertSame(0, PurchaseOrder::count(), 'nothing in the PO table before approval');

        // reject needs a reason; one click approves the whole PPB document
        $this->postJson("/api/ppb/{$new1->id}/approval", ['action' => 'REJECT'])->assertStatus(422);
        $this->postJson("/api/ppb/{$new1->id}/approval", ['action' => 'APPROVE', 'note' => 'ok'])->assertOk()
            ->assertJsonPath('data.approval.status', 'DISETUJUI')->assertJsonPath('data.approval.by', $admin->name)
            ->assertJsonPath('data.document_lines', 2);
        $this->postJson("/api/ppb/{$new1->id}/approval", ['action' => 'APPROVE'])->assertStatus(422); // already approved
        $this->assertSame(['DIAJUKAN', 'APPROVE'], array_column($this->getJson("/api/ppb/{$new1->id}")->json('data.approval_log'), 'action'));

        // approved -> straight into the PO table: one "Menunggu PO" entry with both lines
        $po = PurchaseOrder::sole();
        $this->assertSame(['MENUNGGU_PO', null, 'PPB/NA/26/X/050'], [$po->status, $po->vendor_id, $po->from_ppb_approval]);
        $this->assertMatchesRegularExpression('#^PO/PPB/\d{2}/[IVX]+/001$#', $po->number);
        $list = $this->getJson('/api/purchase-orders')->assertOk()->json('data');
        $this->assertSame([[$po->number, 'PPB/NA/26/X/050', 'MENUNGGU_PO'], [$po->number, 'PPB/NA/26/X/050', 'MENUNGGU_PO']],
            array_map(fn ($r) => [$r['po_number'], $r['ppb_no'], $r['status']], $list));
        $this->assertSame($po->number, $this->getJson("/api/ppb/{$new1->id}")->json('data.approval_po.number'));
        $this->getJson("/api/purchase-orders/{$po->id}")->assertOk()->assertJsonPath('data.items.0.source_ppb.no_ppb', 'PPB/NA/26/X/050');
        // the approval's PO lines never count as Accurate's PO chain (PPB status, "Belum ada PO" filter)
        $this->assertSame(2, Ppb::where('no_ppb', 'PPB/NA/26/X/050')->whereDoesntHave('poItems')->count());

        // Accurate's real PO for one line arrives -> that "Menunggu PO" line goes, no PO twice, no "PO tanpa approval"
        $this->poFor($new1);
        $svc->scan();
        $this->assertSame(1, PurchaseOrderItem::where('line_status', 'MENUNGGU_PO')->count());
        $this->assertSame(0, DB::table('ppb_approvals')->where('action', 'PO_TANPA_APPROVAL')->count());

        // rejected after all -> its "Menunggu PO" entry leaves the PO table
        $this->postJson("/api/ppb/{$new1->id}/approval", ['action' => 'REJECT', 'note' => 'batal'])->assertOk()->assertJsonPath('data.approval_po', null);
        $this->assertSame(0, PurchaseOrder::where('status', 'MENUNGGU_PO')->count());
        $this->assertSame(1, PurchaseOrder::count(), "Accurate's PO stays");
    }

    public function test_po_made_in_accurate_before_approval_is_flagged_once(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $ppb = $this->ppb('PPB/NA/26/X/060', '2026-10-22');
        $svc = app(PpbApprovalService::class);
        $svc->scan();
        $this->poFor($ppb);
        $svc->scan();
        $svc->scan();

        $flag = DB::table('ppb_approvals')->where(['no_ppb' => 'PPB/NA/26/X/060', 'action' => 'PO_TANPA_APPROVAL'])->get();
        $this->assertCount(1, $flag);
        $this->assertStringContainsString('BL/D/26/X/900', $flag[0]->note);
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $admin->id)->count(), 'waiting + PO without approval');
        $this->assertSame(['PPB/NA/26/X/060'], array_column($this->getJson('/api/ppb?approval=PO_TANPA_APPROVAL')->json('data'), 'no_ppb'));
        $this->assertTrue($this->getJson("/api/ppb/{$ppb->id}")->json('data.approval.po_without_approval'));

        // approving afterwards clears the red flag
        $this->postJson("/api/ppb/{$ppb->id}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.po_without_approval', false);
        $this->assertCount(0, $this->getJson('/api/ppb?approval=PO_TANPA_APPROVAL')->json('data'));
    }

    public function test_an_approval_without_a_po_entry_gets_one_on_the_next_scan(): void
    {
        $admin = $this->actingAsRole('super_admin');
        $ppb = $this->ppb('PPB/NA/26/X/080', '2026-10-24');
        // approved before entries existed: only the log row
        DB::table('ppb_approvals')->insert(['no_ppb' => $ppb->no_ppb, 'action' => 'APPROVE', 'user_id' => $admin->id, 'user_name' => $admin->name, 'created_at' => now()]);
        $svc = app(PpbApprovalService::class);
        $svc->scan();
        $svc->scan();
        $this->assertSame(['MENUNGGU_PO'], PurchaseOrder::pluck('status')->all(), 'one entry, made once');
        $this->assertSame($ppb->id, PurchaseOrderItem::sole()->approved_ppb_id);
    }

    public function test_only_approvers_can_approve(): void
    {
        $ppb = $this->ppb('PPB/NA/26/X/070', '2026-10-23');
        $this->actingAsRole('admin_gudang'); // can see PPB, has no ppb.approve
        $this->getJson("/api/ppb/{$ppb->id}")->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU');
        $this->postJson("/api/ppb/{$ppb->id}/approval", ['action' => 'APPROVE'])->assertForbidden();
        $this->assertSame(0, DB::table('ppb_approvals')->whereIn('action', ['APPROVE', 'REJECT'])->count());
    }
}
