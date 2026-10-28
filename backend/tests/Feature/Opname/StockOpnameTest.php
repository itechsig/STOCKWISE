<?php

namespace Tests\Feature\Opname;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Site;
use App\Models\StockOpname;
use App\Models\Warehouse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** brief §AP — TC-SO-001..006 */
class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::factory()->create(['code' => 'SIG-SDA']);
        $this->warehouse = Warehouse::factory()->create(['site_id' => $site->id]);
    }

    private function stockedItem(float $qty): Item
    {
        $item = Item::factory()->create();
        Inventory::create([
            'item_id' => $item->id, 'warehouse_id' => $this->warehouse->id,
            'actual_qty' => $qty, 'reserved_qty' => 0, 'stock_known' => true,
        ]);

        return $item;
    }

    private function scheduleAndStart(): StockOpname
    {
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('anak_gudang');
        $this->postJson("/api/stock-opnames/{$id}/start")->assertOk();

        return StockOpname::find($id);
    }

    private function enterCount(StockOpname $o, int $itemId, float $physical, ?string $note = null): void
    {
        $line = $o->items()->where('item_id', $itemId)->first();
        $this->putJson("/api/stock-opnames/{$o->id}/items/{$line->id}", array_filter([
            'physical_qty' => $physical, 'note' => $note,
        ], fn ($v) => $v !== null))->assertOk();
    }

    public function test_tc_so_001_equal_no_note_needed(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 100);

        $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');
    }

    public function test_tc_so_002_diff_without_note_rejected(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95);

        $res = $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertStatus(422)
            ->assertJsonValidationErrors('items');
        $this->assertStringContainsString($item->code, $res->json('errors.items.0'), 'names the item so the counter can find it');
    }

    public function test_submit_with_uncounted_rows_says_how_many(): void
    {
        $this->stockedItem(100);
        $this->stockedItem(50);
        $o = $this->scheduleAndStart();

        $res = $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertStatus(422);
        $this->assertStringContainsString('Masih 2 barang belum diisi', $res->json('errors.items.0'));
    }

    public function test_index_filters_by_source_and_number(): void
    {
        $this->stockedItem(100);
        $internal = $this->scheduleAndStart();
        $acc = \App\Models\StockOpname::forceCreate([
            'number' => 'IA-ACC-0001', 'site_id' => $internal->site_id, 'warehouse_id' => $internal->warehouse_id, 'type' => 'FULL',
            'status' => 'COMPLETED', 'scheduled_date' => now()->toDateString(), 'accurate_itemadj_id' => 777,
        ]);

        $this->getJson('/api/stock-opnames?source=internal')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $internal->id);
        $this->getJson('/api/stock-opnames?source=accurate')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $acc->id);
        $this->getJson('/api/stock-opnames?search=ACC-00')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'IA-ACC-0001');
    }

    public function test_tc_so_003_diff_with_note_ok(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');

        $this->postJson("/api/stock-opnames/{$o->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');
    }

    public function test_tc_so_004_approve_creates_adjustment(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'APPROVED']],
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(95.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertDatabaseHas('stock_adjustments', ['qty_before' => 100, 'qty_after' => 95]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'STOCK_ADJUSTMENT']);
    }

    public function test_tc_so_005_reject_no_adjustment(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, 'salah hitung');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'REJECTED']],
        ])->assertOk();

        $this->assertSame(100.0, (float) Inventory::where('item_id', $item->id)->first()->actual_qty);
        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_tc_so_006_recount(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, 'ragu');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", [
            'decisions' => [['id' => $line->id, 'decision' => 'RECOUNT']],
        ])->assertOk()->assertJsonPath('data.status', 'RECOUNT_REQUIRED');
    }

    public function test_anak_gudang_cannot_approve(): void
    {
        $o = $this->scheduleAndStart();
        $this->actingAsRole('anak_gudang');
        $this->postJson("/api/stock-opnames/{$o->id}/review", ['decisions' => [['id' => 1, 'decision' => 'APPROVED']]])
            ->assertForbidden();
    }

    /**
     * Real process: admin_gudang schedules, admin lapangan (role lapangan_gudang) is the one
     * who actually walks the floor, counts, and submits — admin_gudang reviews/approves.
     */
    public function test_lapangan_gudang_does_the_physical_count_and_submit(): void
    {
        $item = $this->stockedItem(100);

        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('lapangan_gudang');
        $this->postJson("/api/stock-opnames/{$id}/start")->assertOk();

        $o = StockOpname::find($id);
        $line = $o->items()->where('item_id', $item->id)->first();
        $this->putJson("/api/stock-opnames/{$id}/items/{$line->id}", ['physical_qty' => 95, 'note' => '5 pcs rusak'])
            ->assertOk();
        $this->postJson("/api/stock-opnames/{$id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'PENDING_REVIEW');

        // counting is not scheduling or approving — admin lapangan stays scoped to that
        $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id, 'scheduled_date' => now()->toDateString(), 'type' => 'FULL',
        ])->assertForbidden();
        $this->postJson("/api/stock-opnames/{$id}/review", ['decisions' => [['id' => $line->id, 'decision' => 'APPROVED']]])
            ->assertForbidden();

        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/stock-opnames/{$id}/review", ['decisions' => [['id' => $line->id, 'decision' => 'APPROVED']]])
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    /**
     * Real bug: scheduling used to read the item list from `inventory`, so an item with no
     * inventory row yet (the exact, common case for a brand-new item, or — per
     * docs/assumptions.md NC-4 — an OPENING opname done specifically because no inventory row
     * exists yet) never got a line at all. Item lines must come from Master Barang.
     */
    public function test_opening_opname_includes_items_with_no_inventory_row_yet(): void
    {
        $item = Item::factory()->create(); // no Inventory row created for it at all

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'OPENING',
        ])->assertCreated();

        $res->assertJsonPath('data.items_count', 1);

        $opname = StockOpname::find($res->json('data.id'));
        $line = $opname->items()->where('item_id', $item->id)->first();
        $this->assertNotNull($line, 'item with no prior inventory row must still get an opname line');
        $this->assertSame(0.0, (float) $line->system_qty);
    }

    public function test_full_opname_mixes_items_with_and_without_inventory(): void
    {
        $known = $this->stockedItem(50);
        $new = Item::factory()->create();

        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated()->json('data.id');

        $opname = StockOpname::find($id);
        $this->assertSame(50.0, (float) $opname->items()->where('item_id', $known->id)->first()->system_qty);
        $this->assertSame(0.0, (float) $opname->items()->where('item_id', $new->id)->first()->system_qty);
    }

    public function test_partial_opname_includes_every_requested_item_even_without_inventory(): void
    {
        $a = Item::factory()->create();
        $b = Item::factory()->create();

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'PARTIAL',
            'item_ids' => [$a->id, $b->id],
        ])->assertCreated();

        $res->assertJsonPath('data.items_count', 2);
    }

    public function test_full_opname_excludes_items_assigned_to_a_different_warehouse(): void
    {
        $otherWarehouse = Warehouse::factory()->create(['site_id' => $this->warehouse->site_id]);
        Item::factory()->create(['default_warehouse_id' => $otherWarehouse->id]);
        $mine = Item::factory()->create(['default_warehouse_id' => $this->warehouse->id]);
        $unassigned = Item::factory()->create();

        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated()->json('data.id');

        $opname = StockOpname::find($id);
        $this->assertNotNull($opname->items()->where('item_id', $mine->id)->first());
        $this->assertNotNull($opname->items()->where('item_id', $unassigned->id)->first());
        $this->assertSame(2, $opname->items()->count());
    }

    public function test_schedule_ignores_inactive_items(): void
    {
        Item::factory()->create(['is_active' => false]);

        $this->actingAsRole('admin_gudang');
        $res = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'FULL',
        ])->assertCreated();

        $res->assertJsonPath('data.items_count', 0);
    }

    /**
     * Blind count: admin lapangan (opname.count, no opname.review) must never see system_qty —
     * or anything derived from it (difference/match_status/diff_label), since combined with the
     * physical_qty they themselves typed, either would let them back-calculate system_qty and
     * just parrot it back instead of counting for real.
     */
    public function test_counter_role_never_sees_system_qty_or_derived_fields(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');

        // still acting as anak_gudang (the counter) from scheduleAndStart()/enterCount()
        $res = $this->getJson("/api/stock-opnames/{$o->id}")->assertOk();
        $line = collect($res->json('data.items'))->firstWhere('item_id', $item->id);

        $this->assertArrayNotHasKey('system_qty', $line);
        $this->assertArrayNotHasKey('difference', $line);
        $this->assertArrayNotHasKey('match_status', $line);
        $this->assertArrayNotHasKey('diff_label', $line);
        $this->assertSame(95.0, (float) $line['physical_qty'], 'counter must still see what they themselves entered');
    }

    public function test_reviewer_sees_valid_invalid_verdict_and_signed_difference(): void
    {
        $match = $this->stockedItem(100);
        $mismatch = $this->stockedItem(50);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $match->id, 100); // no note needed, no difference
        $this->enterCount($o, $mismatch->id, 47, 'kurang 3');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/stock-opnames/{$o->id}")->assertOk();
        $items = collect($res->json('data.items'));

        $matchLine = $items->firstWhere('item_id', $match->id);
        $this->assertSame('VALID', $matchLine['match_status']);
        $this->assertSame('0', $matchLine['diff_label']);
        $this->assertEquals(100, $matchLine['system_qty']);

        $mismatchLine = $items->firstWhere('item_id', $mismatch->id);
        $this->assertSame('INVALID', $mismatchLine['match_status']);
        $this->assertSame('-3', $mismatchLine['diff_label']);
        $this->assertEquals(50, $mismatchLine['system_qty']);
    }

    public function test_show_reports_correct_counts_not_just_index(): void
    {
        // Pre-existing bug found while verifying the above: show() never loadCount()'d
        // items/counted_count/diff_count (only index() did), so the detail page's own
        // "X/Y dihitung" and "Z invalid SO" summary always displayed as 0/0/0.
        $a = $this->stockedItem(100);
        $b = $this->stockedItem(50);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $a->id, 100);
        $this->enterCount($o, $b->id, 47, 'kurang 3');

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/stock-opnames/{$o->id}")->assertOk();

        $res->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.counted_count', 2)
            ->assertJsonPath('data.diff_count', 1);
    }

    public function test_uncounted_line_has_no_verdict_yet(): void
    {
        $this->stockedItem(100);
        $o = $this->scheduleAndStart();

        $this->actingAsRole('admin_gudang');
        $res = $this->getJson("/api/stock-opnames/{$o->id}")->assertOk();
        $line = $res->json('data.items.0');

        $this->assertNull($line['match_status']);
        $this->assertNull($line['diff_label']);
    }

    /**
     * One print view serves two real needs: printed right after scheduling (physical_qty still
     * empty) it's a blank count sheet to carry into the warehouse; printed after review it's the
     * results report — same endpoint, same data. Blind count still applies to who sees system_qty.
     */
    public function test_print_before_counting_is_a_blank_sheet(): void
    {
        $item = $this->stockedItem(100);
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson('/api/stock-opnames', [
            'warehouse_id' => $this->warehouse->id, 'scheduled_date' => now()->toDateString(),
            'type' => 'PARTIAL', 'item_ids' => [$item->id],
        ])->json('data.id');

        $res = $this->get("/api/stock-opnames/{$id}/print")->assertOk();
        $res->assertHeader('content-type', 'text/html; charset=UTF-8');
        $html = $res->streamedContent();
        $this->assertStringContainsString($item->code, $html);
        $this->assertStringContainsString('Dihitung oleh', $html);
    }

    public function test_print_after_review_shows_filled_results_for_reviewer(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $item->id, 95, '5 pcs rusak');
        $this->postJson("/api/stock-opnames/{$o->id}/submit");

        $this->actingAsRole('admin_gudang');
        $line = $o->items()->first();
        $this->postJson("/api/stock-opnames/{$o->id}/review", ['decisions' => [['id' => $line->id, 'decision' => 'APPROVED']]]);

        $html = $this->get("/api/stock-opnames/{$o->id}/print")->assertOk()->streamedContent();
        $this->assertStringContainsString('>95<', $html); // physical qty printed
        $this->assertStringContainsString('>100<', $html); // system qty printed — reviewer sees it
        $this->assertStringContainsString('-5', $html); // signed difference
    }

    public function test_print_hides_system_qty_for_counter_only_role(): void
    {
        $item = $this->stockedItem(100);
        $o = $this->scheduleAndStart(); // acting as anak_gudang (counter, not reviewer) afterwards

        $html = $this->get("/api/stock-opnames/{$o->id}/print")->assertOk()->streamedContent();
        $this->assertStringContainsString($item->code, $html);
        $this->assertStringNotContainsString('<th>Sistem</th>', $html);
        $this->assertStringNotContainsString('<th>Selisih</th>', $html);
    }

    public function test_npbg_and_ppb_columns_count_the_lines_of_each_item(): void
    {
        $used = $this->stockedItem(5);
        $unused = $this->stockedItem(5);
        foreach ([1, 2, 3] as $seq) {
            \App\Models\Npbg::create(['accurate_arinvoice_id' => 70, 'accurate_seq' => $seq, 'no_npbg' => "NA/26/IX/00$seq", 'tgl_npbg' => '2026-09-01', 'kode_barang' => $used->code, 'kuantitas' => 1]);
        }
        \App\Models\Ppb::create(['accurate_reqid' => 80, 'accurate_seq' => 0, 'no_ppb' => 'PPB/NA/26/IX/001', 'tgl_ppb' => '2026-09-01', 'kode_barang' => $used->code, 'kuantitas' => 1, 'status' => 'OPEN']);
        $o = $this->scheduleAndStart();

        $rows = collect($this->getJson("/api/stock-opnames/{$o->id}")->assertOk()->json('data.items'))->keyBy('item_id');
        $this->assertSame([3, 1], [$rows[$used->id]['npbg_count'], $rows[$used->id]['ppb_count']]);
        $this->assertSame([0, 0], [$rows[$unused->id]['npbg_count'], $rows[$unused->id]['ppb_count']]);

        $line = collect($this->getJson('/api/stock-opnames/lines')->assertOk()->json('data'))->firstWhere('item_id', $used->id);
        $this->assertSame([3, 1], [$line['npbg_count'], $line['ppb_count']]);

        // period: NPBG of 2026-08-15 is outside 1–30 Sept
        \App\Models\Npbg::where('accurate_seq', 3)->update(['tgl_npbg' => '2026-08-15']);
        $period = $this->getJson("/api/stock-opnames/{$o->id}/usage?date_from=2026-09-01&date_to=2026-09-30")->assertOk()->json('data');
        $this->assertSame(['npbg' => 2, 'ppb' => 1], $period['counts'][$used->code]);
        $this->assertSame(['npbg' => 0, 'ppb' => 0], $period['counts'][$unused->code]);
        $this->assertSame(['npbg' => 1, 'ppb' => 0], $this->getJson("/api/stock-opnames/{$o->id}/usage?date_to=2026-08-31")->json("data.counts")[$used->code]);

        $line = collect($this->getJson('/api/stock-opnames/lines?usage_from=2026-09-01&usage_to=2026-09-30')->assertOk()->json('data'))->firstWhere('item_id', $used->id);
        $this->assertSame([2, 1], [$line['npbg_count'], $line['ppb_count']]);
        $this->getJson("/api/stock-opnames/{$o->id}/usage?date_from=bukan-tanggal")->assertStatus(422);
    }

    public function test_lines_lists_every_item_across_documents_with_blind_count_respected(): void
    {
        $a = $this->stockedItem(100);
        $b = $this->stockedItem(50);
        $o = $this->scheduleAndStart();
        $this->enterCount($o, $a->id, 95, 'rusak');
        $this->enterCount($o, $b->id, 50);

        // counter: sees the lines, never the system qty / difference
        $res = $this->getJson('/api/stock-opnames/lines?source=internal')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.opname.number', $o->number);
        $this->assertArrayNotHasKey('system_qty', $res->json('data.0'));
        $this->assertArrayNotHasKey('difference', $res->json('data.0'));

        // reviewer: sees them, can filter to differences and search by item code
        $this->actingAsRole('admin_gudang');
        $this->getJson('/api/stock-opnames/lines?only_diff=1')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.item_code', $a->code)
            ->assertJsonPath('data.0.diff_label', '-5');
        $this->getJson('/api/stock-opnames/lines?search='.urlencode($b->code))->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/stock-opnames/lines?source=accurate')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/stock-opnames/lines?date_from=2000-01-01&date_to=2000-01-02')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_index_previews_the_first_item_of_each_document(): void
    {
        $a = $this->stockedItem(100);
        $o = $this->scheduleAndStart();

        $this->getJson('/api/stock-opnames')->assertOk()
            ->assertJsonPath('data.0.id', $o->id)
            ->assertJsonPath('data.0.first_item.code', $a->code);
    }
}
