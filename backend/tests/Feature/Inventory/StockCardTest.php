<?php

namespace Tests\Feature\Inventory;

use App\Models\Item;
use App\Models\StockCard;
use App\Services\Inventory\StockCardService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Kartu Stock: a project (e.g. a CCTV installation) holds many cards, one per item
 * from Master Barang; lines by hand, Sisa computed; printable / PDF per card or per project.
 */
class StockCardTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private Item $cctv;

    private Item $cable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cctv = Item::factory()->create(['code' => 'EAE.0291', 'description' => 'KAMERA CCTV - HIKVISION / DS-2CD1323G2-LIU']);
        $this->cable = Item::factory()->create(['code' => 'EAE.0170', 'description' => 'KABEL UTP CAT6']);
    }

    private function project(string $name, ?string $code = null): int
    {
        return $this->postJson('/api/stock-card-projects', ['name' => $name, 'code' => $code])->assertCreated()->json('data.id');
    }

    private function card(int $projectId, Item $item, array $extra = []): array
    {
        $number = $this->getJson("/api/stock-cards/next-number?item_id={$item->id}&project_id={$projectId}")->assertOk()->json('data');

        return $this->postJson('/api/stock-cards', ['project_id' => $projectId, 'item_id' => $item->id, 'card_number' => $number,
            'person_in_charge' => 'AYU', 'location_warehouse' => 'GUDANG 1', 'location_rack' => 'RAK A.11', 'director_name' => 'BPK. DIREKTUR'] + $extra)
            ->assertCreated()->json('data');
    }

    public function test_a_project_holds_many_cards_one_per_item(): void
    {
        $this->actingAsRole('admin_gudang');
        $a = $this->project('Instalasi CCTV Gedung A', 'PRJ-CCTV-A');
        $b = $this->project('Instalasi CCTV Gudang SDA');

        $cctvA = $this->card($a, $this->cctv);
        $cableA = $this->card($a, $this->cable);
        $this->assertSame(['EAE.0291', 'Instalasi CCTV Gedung A'], [$cctvA['card_number'], $cctvA['project']['name']]);
        $this->assertSame('EAE.0170', $cableA['card_number']);

        // one card per item in a project; the same item may have a card in another project, with the same number
        $this->postJson('/api/stock-cards', ['project_id' => $a, 'item_id' => $this->cctv->id, 'card_number' => 'X'])->assertStatus(422)->assertJsonValidationErrors('item_id');
        $this->postJson('/api/stock-cards', ['project_id' => $a, 'item_id' => $this->cctv->id, 'card_number' => 'EAE.0170'])->assertStatus(422);
        $this->assertSame('EAE.0291', $this->card($b, $this->cctv)['card_number']);
        $this->postJson('/api/stock-cards', ['item_id' => $this->cable->id, 'card_number' => 'Z'])->assertStatus(422)->assertJsonValidationErrors('project_id');

        // the project page lists its cards with their totals
        $this->postJson("/api/stock-cards/{$cctvA['id']}/entries", ['entry_date' => '2026-10-04', 'description' => 'Beli ke P. Anam', 'qty_in' => 15])->assertOk();
        $this->postJson("/api/stock-cards/{$cctvA['id']}/entries", ['entry_date' => '2026-10-05', 'description' => 'Pasang No. 6 & 7', 'qty_out' => 2])->assertOk();
        $p = $this->getJson("/api/stock-card-projects/$a")->assertOk()->json('data');
        $this->assertSame(['EAE.0170', 'EAE.0291'], array_column($p['cards'], 'card_number'));
        $this->assertEquals([15, 2, 13], [$p['cards'][1]['total_masuk'], $p['cards'][1]['total_keluar'], $p['cards'][1]['sisa']]);
        $this->assertEquals([2, 1], array_column(collect($this->getJson('/api/stock-card-projects')->json('data'))->sortBy('id')->values()->all(), 'cards_count'));

        // a project with cards can't be deleted; an empty one can
        $this->deleteJson("/api/stock-card-projects/$a")->assertStatus(422);
        $empty = $this->project('Kosong');
        $this->deleteJson("/api/stock-card-projects/$empty")->assertOk();

        // print every card of the project, one per page
        $html = app(StockCardService::class)->html(StockCard::where('project_id', $a)->orderBy('card_number')->get(), 'Kartu Stock — A');
        $this->assertSame(2, substr_count($html, '<section class="card">'));
        foreach (['Proyek: <b>Instalasi CCTV Gedung A</b>', 'EAE.0291', 'EAE.0170', 'Pasang No. 6 &amp; 7', 'BPK. DIREKTUR'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_lines_balance_and_moving_a_card_between_projects(): void
    {
        $this->actingAsRole('admin_gudang');
        $a = $this->project('A');
        $b = $this->project('B');
        $id = $this->card($a, $this->cctv)['id'];

        $this->postJson("/api/stock-cards/$id/entries", ['entry_date' => '2026-10-04', 'qty_in' => 15])->assertOk();
        $c = $this->postJson("/api/stock-cards/$id/entries", ['entry_date' => '2026-10-01', 'description' => 'Sisa lama', 'qty_in' => 3])->assertOk()->json('data');
        $this->assertEquals([3, 18], array_column($c['rows'], 'sisa'), 'date order');
        $this->postJson("/api/stock-cards/$id/entries", ['entry_date' => '2026-10-05'])->assertStatus(422);

        // move to project B; B may not hold the same item twice
        $this->putJson("/api/stock-cards/$id", ['project_id' => $b, 'card_number' => 'EAE.0291'])->assertOk()->assertJsonPath('data.project.name', 'B');
        $other = $this->card($a, $this->cctv)['id'];
        $this->putJson("/api/stock-cards/$other", ['project_id' => $b, 'card_number' => 'EAE.0291-X'])->assertStatus(422);

        // cards without a project (made before projects existed) are listed apart
        StockCard::whereKey($other)->update(['project_id' => null]);
        $this->assertSame([$other], collect($this->getJson('/api/stock-cards?project_id=none')->json('data'))->pluck('id')->all());
        $this->assertSame(1, $this->getJson('/api/stock-card-projects')->json('unassigned_cards'));
    }

    public function test_permissions_upload_and_item_search(): void
    {
        $this->actingAsRole('admin_gudang');
        $a = $this->project('A');
        $id = $this->card($a, $this->cctv)['id'];
        $line = $this->postJson("/api/stock-cards/$id/entries", ['entry_date' => '2026-10-04', 'qty_in' => 15])->json('data.rows.0.id');

        Storage::fake('local');
        $pdf = 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 test');
        $this->postJson('/api/tracking-photos', ['entity' => 'stock_card_entry', 'entity_id' => $line, 'slot' => 'doc', 'photo' => $pdf, 'file_name' => 'nota beli'])->assertCreated();
        $this->assertSame('nota beli', $this->getJson("/api/stock-cards/$id")->json('data.rows.0.files.0.file_name'));

        // any number of files per line: slot "auto" adds one instead of replacing
        $jpg = 'data:image/jpeg;base64,'.base64_encode('fake-jpg');
        $f1 = $this->postJson('/api/tracking-photos', ['entity' => 'stock_card_entry', 'entity_id' => $line, 'slot' => 'auto', 'photo' => $jpg, 'file_name' => 'foto 1'])->assertCreated()->json('data.id');
        $this->postJson('/api/tracking-photos', ['entity' => 'stock_card_entry', 'entity_id' => $line, 'slot' => 'auto', 'photo' => $jpg, 'file_name' => 'foto 1'])->assertCreated();
        $this->postJson('/api/tracking-photos', ['entity' => 'stock_card_entry', 'entity_id' => $line, 'slot' => 'auto', 'photo' => $pdf, 'file_name' => 'surat jalan'])->assertCreated();
        $files = $this->getJson("/api/stock-cards/$id")->json('data.rows.0.files');
        $this->assertSame(['nota beli', 'foto 1', 'foto 1', 'surat jalan'], array_column($files, 'file_name'));
        $this->assertSame(['application/pdf', 'image/jpeg'], [$files[3]['mime'], $files[1]['mime']]);
        $this->deleteJson("/api/tracking-photos/$f1")->assertOk();
        $this->assertCount(3, $this->getJson("/api/stock-cards/$id")->json('data.rows.0.files'));
        // other modules keep their fixed slots
        try {
            app(\App\Services\Tracking\TrackingPhotoService::class)->put('stpp', 1, 'auto', 'x', 'jpg');
            $this->fail('fixed-slot module accepted "auto"');
        } catch (\Illuminate\Validation\ValidationException) {
        }
        $this->postJson('/api/tracking-photos', ['entity' => 'stock_card_entry', 'entity_id' => $line, 'slot' => 'xyz', 'photo' => $jpg])->assertStatus(422);

        $found = collect($this->getJson('/api/stock-card/items?search=eae.0291')->json('data'))->first();
        $this->assertSame(['EAE.0291', $a], [$found['code'], $found['cards'][0]['project_id']], 'the picker knows which projects have a card');
        $this->assertEqualsCanonicalizing(['EAE.0291'], collect($this->getJson('/api/stock-card/items?search=kamera hikvision')->json('data'))->pluck('code')->all());

        $this->actingAsRole('karyawan');
        $this->getJson("/api/stock-cards/$id")->assertForbidden();
        $this->getJson('/api/stock-card-projects')->assertForbidden();
        $this->postJson('/api/stock-card-projects', ['name' => 'X'])->assertForbidden();
    }
}
