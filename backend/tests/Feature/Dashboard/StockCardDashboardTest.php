<?php

namespace Tests\Feature\Dashboard;

use App\Models\Item;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Dashboard "Kartu Stock — Barang Masuk & Keluar" — GET /api/dashboard/stock-cards. */
class StockCardDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private int $a;

    private int $cam;

    private int $cable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 10:00', 'Asia/Jakarta')); // a Thursday
        $unit = fn ($code) => Unit::firstOrCreate(['code' => $code], ['name' => $code])->id;
        $this->cam = Item::factory()->create(['code' => 'EAE.0291', 'unit_id' => $unit('PCS')])->id;
        $this->cable = Item::factory()->create(['code' => 'EAE.0170', 'unit_id' => $unit('ROLL')])->id;
        $nvr = Item::factory()->create(['code' => 'EAE.0420'])->id;

        $project = fn ($name, $status = 'ACTIVE') => DB::table('projects')->insertGetId(['name' => $name, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $card = fn ($p, $i) => DB::table('stock_cards')->insertGetId(['project_id' => $p, 'item_id' => $i, 'card_number' => "K$p-$i", 'created_at' => now(), 'updated_at' => now()]);
        $line = fn ($c, $date, $in, $out) => DB::table('stock_card_entries')->insert(['stock_card_id' => $c, 'entry_date' => $date, 'qty_in' => $in, 'qty_out' => $out]);

        $this->a = $project('CCTV Gedung A');
        $c1 = $card($this->a, $this->cam);
        $line($c1, '2026-10-06', 15, 0);   // Monday this week
        $line($c1, '2026-10-08', 0, 2);    // today
        $line($c1, '2026-09-30', 0, 1);    // last week, last month
        $c2 = $card($this->a, $this->cable);
        $line($c2, '2026-10-07', 1, 0);
        $line($c2, '2026-10-07', 0, 3);    // sisa -2
        $line($c2, '2025-01-01', 9, 0);    // outside every period, still in the balance
        $card($this->a, $nvr);             // no lines

        $b = $project('CCTV Gudang Lama', 'DONE');
        $line($card($b, $this->cam), '2026-10-08', 4, 0);
    }

    public function test_daily_weekly_monthly_buckets_and_item_report(): void
    {
        $this->actingAsRole('admin_gudang');

        $d = $this->getJson('/api/dashboard/stock-cards?period=daily')->assertOk()->json('data');
        $this->assertCount(30, $d['series']);
        $this->assertSame(['2026-09-09', '2026-10-08'], [$d['from'], $d['to']]);
        $this->assertSame('lines', $d['measure'], 'several items: lines, not mixed-unit quantities');
        $today = end($d['series']);
        $this->assertSame([1, 1], [$today['lines_in'], $today['lines_out']]); // cam: project B in 4, project A out 2
        $this->assertSame(['lines_in' => 3, 'lines_out' => 3, 'qty_in' => null, 'qty_out' => null, 'items_moved' => 2, 'negative_items' => 0], $d['totals']);
        $this->assertSame(['CCTV Gedung A', 'CCTV Gudang Lama'], array_column($d['filters']['projects'], 'name'));

        $w = $this->getJson('/api/dashboard/stock-cards?period=weekly')->json('data');
        $this->assertCount(12, $w['series']);
        $this->assertSame(['2026-10-05', '2026-10-11'], [end($w['series'])['start'], end($w['series'])['end']]);
        $this->assertSame([3, 2], [end($w['series'])['lines_in'], end($w['series'])['lines_out']]);

        $m = $this->getJson('/api/dashboard/stock-cards?period=monthly')->json('data');
        $this->assertSame(['2025-11', '2026-10'], [$m['series'][0]['key'], end($m['series'])['key']]);
        $this->assertSame(1, $m['series'][10]['lines_out'], 'September');

        // one project: per-item quantities with unit and the balance over all dates
        $p = $this->getJson("/api/dashboard/stock-cards?period=monthly&project_id={$this->a}")->json('data');
        $byCode = collect($p['items'])->keyBy('code');
        $this->assertEquals(['qty_in' => 15, 'qty_out' => 3, 'unit' => 'PCS', 'sisa' => 12],
            array_intersect_key($byCode['EAE.0291'], array_flip(['qty_in', 'qty_out', 'unit', 'sisa'])));
        $this->assertEquals(['qty_in' => 1, 'qty_out' => 3, 'unit' => 'ROLL', 'sisa' => 7], array_intersect_key($byCode['EAE.0170'], array_flip(['qty_in', 'qty_out', 'unit', 'sisa'])));
        $this->assertCount(3, $p['filters']['items'], 'the item filter lists every carded item of the project');

        // one item: the chart sums quantities in its unit
        $i = $this->getJson("/api/dashboard/stock-cards?period=weekly&item_id={$this->cam}")->json('data');
        $this->assertSame(['qty', 'PCS'], [$i['measure'], $i['unit']]);
        $this->assertEquals([19, 2], [end($i['series'])['qty_in'], end($i['series'])['qty_out']]);
        $this->assertEquals([19, 3], [$i['totals']['qty_in'], $i['totals']['qty_out']]);
    }

    public function test_permission_and_validation(): void
    {
        $this->actingAsRole('admin_gudang');
        $this->getJson('/api/dashboard/stock-cards?period=yearly')->assertStatus(422);

        $this->actingAsRole('karyawan');
        $this->getJson('/api/dashboard/stock-cards')->assertForbidden();
    }
}
