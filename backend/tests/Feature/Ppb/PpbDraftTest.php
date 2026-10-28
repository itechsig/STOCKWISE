<?php

namespace Tests\Feature\Ppb;

use App\Models\Item;
use App\Models\Ppb;
use App\Models\PpbDraft;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Master Produk (BARANG / JASA) and Restock → Draft PPB → PPB. */
class PpbDraftTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_restock_makes_an_editable_draft_that_becomes_a_ppb(): void
    {
        $a = Item::factory()->create(['code' => 'BRG.1', 'description' => 'BEARING 6205', 'is_active' => true]);
        $b = Item::factory()->create(['code' => 'BRG.2', 'description' => 'SEAL 40', 'is_active' => true]);
        $this->actingAsRole('admin_gudang');

        $draft = $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $a->id], ['item_id' => $b->id, 'qty' => 7]]])
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data');
        $this->assertMatchesRegularExpression('#^RST/\d{2}/[IVX]+/001$#', $draft['number']);
        $this->assertSame(['BRG.1', 'BRG.2'], array_column($draft['lines'], 'kode_barang'));
        $this->assertSame(7.0, (float) $draft['lines'][1]['qty']);
        $this->assertGreaterThan(0, (float) $draft['lines'][0]['qty'], 'suggested qty never 0');
        $this->assertSame(0, Ppb::count(), 'a draft is not a PPB yet');

        // review: change a qty, drop a line, add a free-text line, fill the header
        $edited = $this->putJson("/api/ppb-drafts/{$draft['id']}", [
            'divisi' => 'GUDANG', 'peminta' => 'JORDY',
            'lines' => [
                ['id' => $draft['lines'][0]['id'], 'item_id' => $a->id, 'qty' => 12],
                ['deskripsi_barang' => 'OLI MESIN 1L', 'qty' => 3, 'satuan' => 'BTL'],
            ],
        ])->assertOk()->json('data');
        $this->assertSame([['BRG.1', 12.0], [null, 3.0]], array_map(fn ($l) => [$l['kode_barang'], (float) $l['qty']], $edited['lines']));
        $this->putJson("/api/ppb-drafts/{$draft['id']}", ['lines' => []])->assertStatus(422);

        // submit → manual PPB rows, draft closed
        $this->postJson("/api/ppb-drafts/{$draft['id']}/submit", ['tgl_ppb' => '2026-10-23'])->assertStatus(422); // no_ppb required
        $done = $this->postJson("/api/ppb-drafts/{$draft['id']}/submit", ['no_ppb' => 'PPB/NA/26/X/090', 'tgl_ppb' => '2026-10-23'])
            ->assertOk()->assertJsonPath('data.status', 'DIAJUKAN')->assertJsonPath('data.no_ppb', 'PPB/NA/26/X/090')->json('data');
        $this->assertNotNull($done['ppb_id']);
        $rows = Ppb::where('no_ppb', 'PPB/NA/26/X/090')->orderBy('id')->get();
        $this->assertSame([['BRG.1', 12.0, 'GUDANG', 'JORDY'], [null, 3.0, 'GUDANG', 'JORDY']],
            $rows->map(fn ($p) => [$p->kode_barang, (float) $p->kuantitas, $p->divisi, $p->peminta])->all());
        $this->assertSame('Restock dari '.$draft['number'], $rows[0]->keterangan);

        // closed for edits
        $this->putJson("/api/ppb-drafts/{$draft['id']}", ['divisi' => 'X'])->assertStatus(422);
        $this->getJson('/api/ppb-drafts')->assertOk()->assertJsonPath('meta.open', 0);
    }

    public function test_a_service_is_jasa_never_restocked_and_filters_master_produk(): void
    {
        $this->actingAsRole('admin_gudang');
        $jasa = Item::factory()->create(['code' => 'JSA.1', 'description' => 'JASA SERVIS AC', 'is_active' => true, 'product_kind' => 'JASA']);
        Item::factory()->create(['code' => 'BRG.9', 'description' => 'KABEL', 'is_active' => true]);

        $this->assertSame(['JSA.1'], array_column($this->getJson('/api/items?product_kind=JASA')->assertOk()->json('data'), 'code'));
        $this->assertSame(['BRG.9'], array_column($this->getJson('/api/items?product_kind=BARANG')->json('data'), 'code'));
        $this->assertSame('JASA', $this->getJson('/api/items?product_kind=JASA')->json('data.0.product_kind'));
        $this->postJson('/api/ppb-drafts', ['items' => [['item_id' => $jasa->id]]])->assertStatus(422);
        $this->assertSame(0, PpbDraft::count());
    }

    public function test_drafts_need_ppb_create(): void
    {
        $this->actingAsRole('karyawan');
        $this->getJson('/api/ppb-drafts')->assertForbidden();
    }
}
