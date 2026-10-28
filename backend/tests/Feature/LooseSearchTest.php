<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Npbg;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accurate descriptions keep their own characters (Ø = diameter, byte 0xD8;
 * no-break spaces) — stored exactly, but searchable the way people type.
 */
class LooseSearchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_o_finds_diameter_and_a_normal_space_finds_a_no_break_space(): void
    {
        $ring = Item::factory()->create(['code' => 'SSP.2774', 'description' => 'PELINDUNG RODA CASTER WHEEL - Ø.T = (35/15) x 7.4mm']);
        $ply = Item::factory()->create(['code' => 'BSP.2715', 'description' => "KAYU TRIPLEK SEMI MERANTI - 2400 x 1200 x\u{00A0}6mm"]);
        Item::factory()->create(['code' => 'X.1', 'description' => 'KERTAS HVS']);
        $this->actingAsRole('admin_gudang');

        $codes = fn (string $s) => collect($this->getJson('/api/items?search='.urlencode($s))->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['SSP.2774'], $codes('O.T = (35/15)'), 'typed O');
        $this->assertSame(['SSP.2774'], $codes('Ø.T = (35/15)'), 'typed Ø still works');
        $this->assertSame(['BSP.2715'], $codes('1200 x 6mm'), 'normal space vs no-break space');

        // the stored text is untouched
        $this->assertSame('PELINDUNG RODA CASTER WHEEL - Ø.T = (35/15) x 7.4mm', $ring->fresh()->description);
        $this->assertStringContainsString("\u{00A0}", $ply->fresh()->description);
    }

    public function test_npbg_search_is_loose_too(): void
    {
        Npbg::create(['accurate_arinvoice_id' => 1, 'accurate_seq' => 0, 'no_npbg' => 'NA/26/IV/268', 'tgl_npbg' => '2026-04-30',
            'kode_barang' => 'SSP.2618', 'deskripsi_barang' => 'BUSHING GI - Ø.P.T = (12.5/15) x 62 x 1mm', 'kuantitas' => 4]);
        $this->actingAsRole('admin_gudang');

        $this->getJson('/api/npbg?search='.urlencode('O.P.T = (12.5/15)'))->assertOk()->assertJsonPath('meta.total', 1);
    }
}
