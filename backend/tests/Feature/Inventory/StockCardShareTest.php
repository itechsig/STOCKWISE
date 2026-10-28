<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\StockCardEntry;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Kartu Stock share links: a project opened without an account — Lihat / Edit / Full. */
class StockCardShareTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private int $project;

    private int $other;

    private int $card;

    private int $otherCard;

    private Item $cam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cam = Item::factory()->create(['code' => 'EAE.0291', 'description' => 'KAMERA CCTV HIKVISION']);
        $this->actingAsRole('admin_gudang');
        $this->project = $this->postJson('/api/stock-card-projects', ['name' => 'CCTV Gedung A'])->json('data.id');
        $this->other = $this->postJson('/api/stock-card-projects', ['name' => 'Proyek Lain'])->json('data.id');
        $this->card = $this->postJson('/api/stock-cards', ['project_id' => $this->project, 'item_id' => $this->cam->id, 'card_number' => 'EAE.0291'])->json('data.id');
        $this->otherCard = $this->postJson('/api/stock-cards', ['project_id' => $this->other, 'item_id' => $this->cam->id, 'card_number' => 'EAE.0291'])->json('data.id');
        $this->postJson("/api/stock-cards/{$this->card}/entries", ['entry_date' => '2026-10-01', 'qty_in' => 10])->assertOk();
    }

    private function link(string $access): string
    {
        $this->actingAsRole('admin_gudang');

        return $this->postJson("/api/stock-card-projects/{$this->project}/share-links", ['access' => $access, 'label' => "tim $access"])
            ->assertCreated()->json('data.token');
    }

    /** Requests as a guest — no account at all. */
    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    public function test_view_link_reads_only(): void
    {
        $t = $this->link('VIEW');
        $base = "/api/public/kartu-stock/$t";
        $this->guest();

        $p = $this->getJson($base)->assertOk()->json('data');
        $this->assertSame(['CCTV Gedung A', false, false, 'VIEW'], [$p['name'], $p['can_edit'], $p['can_create_card'], $p['share']['access']]);
        $this->assertSame([$this->card], array_column($p['cards'], 'id'));
        $c = $this->getJson("$base/cards/{$this->card}")->assertOk()->json('data');
        $this->assertSame([false, false, 10.0], [$c['can_edit'], $c['can_delete'], (float) $c['sisa']]);
        $this->assertNull($c['item']['accurate_qty'], 'the company stock in Accurate is not shown through a link');

        $this->postJson("$base/cards/{$this->card}/entries", ['entry_date' => '2026-10-02', 'qty_out' => 1])->assertForbidden();
        $this->putJson("$base/cards/{$this->card}", ['card_number' => 'X'])->assertForbidden();
        // another project's card can't be reached, and a wrong token opens nothing
        $this->getJson("$base/cards/{$this->otherCard}")->assertNotFound();
        $this->getJson('/api/public/kartu-stock/salah')->assertNotFound();
    }

    public function test_edit_link_writes_lines_header_and_files_but_does_not_delete(): void
    {
        $t = $this->link('EDIT');
        $base = "/api/public/kartu-stock/$t";
        $this->guest();

        $c = $this->postJson("$base/cards/{$this->card}/entries", ['entry_date' => '2026-10-02', 'description' => 'Pasang No. 1', 'qty_out' => 3])->assertOk()->json('data');
        $this->assertEquals([10, 7], array_column($c['rows'], 'sisa'));
        $line = $c['rows'][1]['id'];
        $this->assertNotNull(StockCardEntry::find($line)->share_link_id, 'the line remembers the link it came through');
        $this->assertNull(StockCardEntry::find($line)->created_by);
        $this->putJson("$base/cards/{$this->card}/entries/$line", ['entry_date' => '2026-10-02', 'qty_out' => 4])->assertOk()->assertJsonPath('data.sisa', 6);
        $this->putJson("$base/cards/{$this->card}", ['card_number' => 'EAE.0291', 'location_rack' => 'RAK B', 'person_in_charge' => 'DODI'])
            ->assertOk()->assertJsonPath('data.location_rack', 'RAK B');

        Storage::fake('local');
        $jpg = 'data:image/jpeg;base64,'.base64_encode('fake-jpg');
        $f = $this->postJson("$base/files", ['entity_id' => $line, 'photo' => $jpg, 'file_name' => 'foto pasang'])->assertCreated()->json('data.id');
        $this->postJson("$base/files", ['entity_id' => $line, 'photo' => $jpg])->assertCreated();
        $this->assertCount(2, $this->getJson("$base/cards/{$this->card}")->json('data.rows.1.files'));
        $this->get("$base/files/$f")->assertOk();

        // no deleting, no new cards, and nothing outside the project
        $this->deleteJson("$base/cards/{$this->card}/entries/$line")->assertForbidden();
        $this->deleteJson("$base/files/$f")->assertForbidden();
        $this->deleteJson("$base/cards/{$this->card}")->assertForbidden();
        $this->getJson("$base/items?search=eae")->assertForbidden();
        $otherLine = StockCardEntry::create(['stock_card_id' => $this->otherCard, 'entry_date' => '2026-10-01', 'qty_in' => 1])->id;
        $this->postJson("$base/files", ['entity_id' => $otherLine, 'photo' => $jpg])->assertNotFound();
        $this->putJson("$base/cards/{$this->otherCard}/entries/$otherLine", ['entry_date' => '2026-10-02', 'qty_in' => 9])->assertNotFound();
    }

    public function test_full_link_deletes_and_makes_cards(): void
    {
        $cable = Item::factory()->create(['code' => 'EAE.0170', 'description' => 'KABEL UTP']);
        $t = $this->link('FULL');
        $base = "/api/public/kartu-stock/$t";
        $this->guest();

        $this->assertTrue($this->getJson($base)->json('data.can_create_card'));
        // the picker only tells about this project's cards
        $found = collect($this->getJson("$base/items?search=eae.0291")->json('data'))->first();
        $this->assertSame([$this->project], array_column($found['cards'], 'project_id'));
        $this->assertNull($found['accurate_qty']);
        $number = $this->getJson("$base/next-number?item_id={$cable->id}")->json('data');
        $new = $this->postJson("$base/cards", ['item_id' => $cable->id, 'card_number' => $number])->assertCreated()->json('data');
        $this->assertSame(['CCTV Gedung A', true], [$new['project']['name'], $new['can_delete']]);
        $this->postJson("$base/cards", ['item_id' => $cable->id, 'card_number' => 'Z'])->assertStatus(422);

        $line = $this->getJson("$base/cards/{$this->card}")->json('data.rows.0.id');
        $this->deleteJson("$base/cards/{$this->card}/entries/$line")->assertOk()->assertJsonCount(0, 'data.rows');
        $this->deleteJson("$base/cards/{$new['id']}")->assertOk();
        $this->deleteJson("$base/cards/{$this->otherCard}")->assertNotFound();
    }

    public function test_managing_links(): void
    {
        $t = $this->link('VIEW');
        $this->actingAsRole('admin_gudang');
        $links = $this->getJson("/api/stock-card-projects/{$this->project}/share-links")->assertOk()->json('data');
        $this->assertSame([['VIEW', 'tim VIEW', $t]], array_map(fn ($l) => [$l['access'], $l['label'], $l['token']], $links));
        $this->assertSame(40, strlen($t));

        // raise the access, then delete: the link stops working at once
        $this->putJson("/api/stock-card-share-links/{$links[0]['id']}", ['access' => 'EDIT'])->assertOk()->assertJsonPath('data.access', 'EDIT');
        $this->guest()->postJson("/api/public/kartu-stock/$t/cards/{$this->card}/entries", ['entry_date' => '2026-10-03', 'qty_in' => 1])->assertOk();
        $this->actingAsRole('admin_gudang');
        $this->deleteJson("/api/stock-card-share-links/{$links[0]['id']}")->assertOk();
        $this->guest()->getJson("/api/public/kartu-stock/$t")->assertNotFound();
        $this->assertSame(2, StockCardEntry::where('stock_card_id', $this->card)->count(), 'lines written through a deleted link stay');

        $this->actingAsRole('karyawan');
        $this->postJson("/api/stock-card-projects/{$this->project}/share-links", ['access' => 'FULL'])->assertForbidden();
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/stock-card-projects/{$this->project}/share-links", ['access' => 'OWNER'])->assertStatus(422);
    }
}
