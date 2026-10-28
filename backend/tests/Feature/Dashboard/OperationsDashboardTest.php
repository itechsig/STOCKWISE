<?php

namespace Tests\Feature\Dashboard;

use App\Models\Ppb;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dashboard "Tracking & Pembelian" — GET /api/dashboard/operations. */
class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 10:00', 'Asia/Jakarta'));
    }

    private function ppb(int $reqid, string $date, string $lineStatus, bool $amend = false): Ppb
    {
        return Ppb::create([
            'accurate_reqid' => $reqid, 'accurate_seq' => 0, 'no_ppb' => "PPB/NA/26/IX/$reqid", 'tgl_ppb' => $date,
            'kode_barang' => 'MAI.0001', 'deskripsi_barang' => 'BAUT', 'kuantitas' => 2, 'satuan' => 'PCS', 'qty_diterima' => 0,
            'status' => 'OPEN', 'line_status' => $lineStatus, 'is_amend' => $amend,
        ]);
    }

    private function lend(string $number, string $status, ?string $due): void
    {
        DB::table('lend_transactions')->insert([
            'number' => $number, 'description_raw' => 'KAWAT LAS', 'qty' => 1, 'out_date' => '2026-07-01', 'due_date' => $due, 'status' => $status,
        ]);
    }

    public function test_aging_flow_tracking_and_lists(): void
    {
        $this->ppb(1, '2026-09-28', 'REQUESTED');   // 3 days
        $this->ppb(2, '2026-08-15', 'SHORTAGE');    // 47 days
        $this->ppb(3, '2025-11-01', 'REQUESTED');   // > 90 days, the oldest
        $this->ppb(4, '2025-10-01', 'REQUESTED', amend: true); // amended — not waiting
        $this->ppb(5, '2026-09-01', 'COMPLETED');

        $this->lend('LEND/1', 'ON_LOAN', '2026-09-20');  // past due although the status still says ON_LOAN
        $this->lend('LEND/2', 'OVERDUE', '2026-09-30');
        $this->lend('LEND/3', 'ON_LOAN', '2026-10-20');
        $this->lend('LEND/4', 'RETURNED', '2026-08-01');

        DB::table('tyre_changes')->insert(['nopol_raw' => 'W 1 A', 'change_date' => '2026-09-10', 'status' => 'CLEAR']);

        $this->actingAsRole('admin_gudang');
        $d = $this->getJson('/api/dashboard/operations')->assertOk()->json('data');

        $aging = collect($d['ppb_aging'])->keyBy('name');
        $this->assertSame(['requested' => 1, 'shortage' => 0], array_slice($aging['0–7 hari'], 1));
        $this->assertSame(['requested' => 0, 'shortage' => 1], array_slice($aging['31–60 hari'], 1));
        $this->assertSame(['requested' => 1, 'shortage' => 0], array_slice($aging['> 90 hari'], 1));

        $flow = collect($d['purchase_flow']);
        $this->assertCount(12, $flow);
        $this->assertSame(['2025-10', '2026-09'], [$flow->first()['month'], $flow->last()['month']], '12 complete months');
        $this->assertSame(2, $flow->firstWhere('month', '2026-09')['ppb']);
        $this->assertSame(1, collect($d['tyre_per_month'])->firstWhere('month', '2026-09')['count']);

        $lend = collect($d['tracking'])->firstWhere('key', 'lend');
        $this->assertSame([1, 1, 2], [$lend['done'], $lend['running'], $lend['attention']]);

        $this->assertSame('PPB/NA/26/IX/3', $d['attention']['ppb_oldest'][0]['no_ppb']);
        $this->assertSame(334, $d['attention']['ppb_oldest'][0]['age_days']);
        $this->assertSame(['LEND/1', 'LEND/2'], collect($d['attention']['lend_overdue'])->pluck('number')->all());
        $this->assertSame(11, $d['attention']['lend_overdue'][0]['late_days']);
    }

    public function test_every_role_sees_the_charts_but_document_lists_need_the_permission(): void
    {
        $this->actingAsRole('karyawan');
        $d = $this->getJson('/api/dashboard/operations')->assertOk()->json('data');

        $this->assertCount(7, $d['tracking']);
        $this->assertCount(5, $d['ppb_aging']);
        $this->assertSame([], $d['attention']);
    }
}
