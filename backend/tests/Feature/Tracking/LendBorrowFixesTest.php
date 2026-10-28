<?php

namespace Tests\Feature\Tracking;

use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Site;
use App\Models\Unit;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lend / Borrow fixes (2026-09-29):
 * - qty came back as "1.00" strings, so the UI never offered Ubah/Hapus;
 * - picking an item left the description "-" and the unit empty;
 * - nothing ever marked a loan OVERDUE (no scheduler runs on this server).
 */
class LendBorrowFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::factory()->create(['code' => 'SDA']);
        $unit = Unit::factory()->create(['code' => 'PCS']);
        $this->item = Item::factory()->create(['code' => 'SSP.0001', 'description' => 'REGULATOR O2', 'unit_id' => $unit->id]);
        $this->actingAsRole('admin_gudang', ['site_id' => $site->id]);
    }

    public function test_lend_with_a_picked_item_gets_its_description_unit_and_numeric_qty(): void
    {
        $lend = $this->postJson('/api/lend', ['item_id' => $this->item->id, 'qty' => 2, 'est_days' => 7])
            ->assertCreated()->json('data');

        $this->assertSame('REGULATOR O2', $lend['description']);
        $this->assertSame('PCS', $this->getJson("/api/lend/{$lend['id']}")->json('data.unit'));
        // numbers, not "2.00"/"0.00" strings — the UI compares qty_returned === 0
        $this->assertSame(2, $this->getJson('/api/lend')->json('data.0.qty'));
        $this->assertSame(0, $this->getJson('/api/lend')->json('data.0.qty_returned'));
    }

    public function test_borrow_with_a_picked_item_gets_its_description_and_numeric_qty(): void
    {
        $borrow = $this->postJson('/api/borrow', ['item_id' => $this->item->id, 'qty' => 3, 'lender_name' => 'Bengkel CDO'])
            ->assertCreated()->json('data');

        $this->assertSame('REGULATOR O2', $borrow['description']);
        $this->assertSame(3, $this->getJson('/api/borrow')->json('data.0.qty'));
        $this->assertSame(0, $this->getJson('/api/borrow')->json('data.0.qty_returned'));
    }

    public function test_loan_past_its_due_date_shows_as_overdue_and_can_still_be_returned(): void
    {
        $lend = $this->postJson('/api/lend', [
            'description_raw' => 'Tabung O2', 'qty' => 2, 'est_days' => 3, 'out_date' => now()->subDays(10)->toDateString(),
        ])->assertCreated()->json('data');

        $this->getJson('/api/lend')->assertOk()->assertJsonPath('data.0.status', 'OVERDUE');
        $this->getJson("/api/lend/{$lend['id']}")->assertJsonPath('data.status', 'OVERDUE');

        $this->postJson("/api/lend/{$lend['id']}/return", ['qty' => 2])->assertOk()->assertJsonPath('data.status', 'RETURNED');
        $this->assertSame('RETURNED', LendTransaction::find($lend['id'])->status, 'a returned loan is never re-flagged overdue');
        $this->getJson('/api/lend')->assertJsonPath('data.0.status', 'RETURNED');
    }
}
