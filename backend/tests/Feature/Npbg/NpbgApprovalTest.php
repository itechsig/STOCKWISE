<?php

namespace Tests\Feature\Npbg;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A manual NPBG (Tambah NPBG): Manager → Direktur, then the goods leave the stock. */
class NpbgApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->item = Item::factory()->create(['code' => 'ATK.0001', 'description' => 'KERTAS', 'is_active' => true]);
        app(StockLedgerService::class)->openingBalance($this->item->id, Warehouse::factory()->create()->id, 5);
    }

    private function npbg(float $qty): array
    {
        $this->actingAsRole('admin_gudang');

        return $this->postJson('/api/npbg', ['pelanggan' => 'HRD', 'tipe_npbg' => 'Non Penjualan', 'kode_barang' => 'ATK.0001', 'deskripsi_barang' => 'KERTAS', 'kuantitas' => $qty, 'satuan' => 'RIM', 'allow_duplicate' => true]) // several NPBG of one item on purpose
            ->assertCreated()->json('data');
    }

    private function stock(): float
    {
        return (float) DB::table('inventory')->where('item_id', $this->item->id)->sum('actual_qty');
    }

    public function test_manager_then_direktur_then_the_stock_goes_out(): void
    {
        $n = $this->npbg(3);
        $this->getJson("/api/npbg/{$n['id']}")->assertJsonPath('data.approval.status', 'MENUNGGU')->assertJsonPath('data.approval.step', 'MANAGER');
        $this->assertSame(5.0, $this->stock(), 'nothing leaves before the approval');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertForbidden(); // admin gudang is no approver

        $this->actingAsRole('manager');
        $this->getJson('/api/approvals/purchasing')->assertOk()->assertJsonPath('data.npbg.0.step', 'MANAGER');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU_DIREKTUR');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertStatus(422);
        $this->assertSame(5.0, $this->stock());

        $this->actingAsRole('direktur');
        $this->getJson('/api/approvals/count')->assertJsonPath('data.total', 1);
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'DISETUJUI')->assertJsonPath('data.can_decide', false);
        $this->assertSame(2.0, $this->stock());
        $this->getJson('/api/approvals/purchasing')->assertJsonPath('data.total', 0);
    }

    public function test_manager_and_direktur_cannot_change_the_quantity(): void
    {
        $n = $this->npbg(3);
        // a quantity that is not right goes back to admin ("Kembalikan"), the approver does not change it
        $this->actingAsRole('manager');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE', 'quantities' => [$n['id'] => 2], 'note' => 'Cukup 2 dulu'])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU_DIREKTUR');
        $this->actingAsRole('direktur');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE', 'quantities' => [$n['id'] => 4]])->assertStatus(422)->assertJsonValidationErrors('quantities');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'DISETUJUI')->assertJsonPath('data.kuantitas', 3);
    }

    public function test_an_npbg_sent_back_is_revised_by_admin_and_approved_again(): void
    {
        $n = $this->npbg(3);
        $this->actingAsRole('direktur');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'RETURN'])->assertStatus(422)->assertJsonValidationErrors('note');
        $back = $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'RETURN', 'note' => 'Pelanggan salah'])->assertOk()->json('data');
        $this->assertSame(['DIKEMBALIKAN', false], [$back['approval']['status'], $back['can_decide']]);
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertStatus(422);
        $this->assertSame(5.0, $this->stock(), 'nothing leaves while it is revised');
        $this->actingAsRole('admin_gudang');
        $this->assertTrue($this->getJson("/api/npbg/{$n['id']}")->json('data.can_resubmit'));
        $this->postJson("/api/npbg/{$n['id']}/resubmit", ['quantities' => [$n['id'] => 2], 'note' => 'Diperbaiki'])->assertOk()->assertJsonPath('data.approval.status', 'MENUNGGU');
        $this->actingAsRole('direktur');
        $this->postJson("/api/npbg/{$n['id']}/approval", ['action' => 'APPROVE'])->assertOk()->assertJsonPath('data.approval.status', 'DISETUJUI');
        $this->assertSame(3.0, $this->stock());
    }

    public function test_the_direktur_bypasses_and_a_rejection_needs_a_reason(): void
    {
        $a = $this->npbg(1);
        $this->actingAsRole('direktur');
        $done = $this->postJson("/api/npbg/{$a['id']}/approval", ['action' => 'APPROVE'])->assertOk()->json('data');
        $this->assertSame(['APPROVE_MANAGER', 'APPROVE'], array_column($done['approval_log'], 'action'));
        $this->assertSame(4.0, $this->stock());

        $b = $this->npbg(10);
        $this->actingAsRole('manager');
        $this->postJson("/api/npbg/{$b['id']}/approval", ['action' => 'REJECT'])->assertStatus(422);
        $this->postJson("/api/npbg/{$b['id']}/approval", ['action' => 'REJECT', 'note' => 'Tidak perlu'])->assertOk()->assertJsonPath('data.approval.status', 'DITOLAK');
        $this->assertSame(4.0, $this->stock());

        // not enough stock: the Direktur cannot approve it
        $c = $this->npbg(9);
        $this->actingAsRole('direktur');
        $this->postJson("/api/npbg/{$c['id']}/approval", ['action' => 'APPROVE'])->assertStatus(422);
        $this->assertSame(4.0, $this->stock());
    }
}
