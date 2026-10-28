<?php

namespace Tests\Feature\Npbg;

use App\Models\Npbg;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** NPBG "Kembali Bekas" tick → a row in Tracking Pengembalian Bekas, sheet "Spare Part Lain". */
class NpbgKembaliBekasTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function line(int $seq, string $desc): Npbg
    {
        return Npbg::create([
            'accurate_arinvoice_id' => 777, 'accurate_seq' => $seq, 'no_npbg' => 'NPBG/NA/26/X/050',
            'tgl_npbg' => '2026-10-19', 'deskripsi_barang' => $desc, 'kuantitas' => 2, 'satuan' => 'PCS',
        ]);
    }

    public function test_tick_tracks_the_line_and_untick_removes_it_while_untouched(): void
    {
        $first = $this->line(0, 'BAN LUAR 7.50-16');
        $second = $this->line(1, 'BAN DALAM 7.50-16');
        $user = $this->actingAsRole('admin_gudang');

        $this->putJson("/api/npbg/{$second->id}/kembali-bekas", ['checked' => true])->assertOk()
            ->assertJsonPath('data.kembali_bekas', true)->assertJsonPath('data.kembali_bekas_status', 'PENDING');
        $this->putJson("/api/npbg/{$second->id}/kembali-bekas", ['checked' => true])->assertOk(); // idempotent

        $rows = DB::table('used_return_other_rows')->get();
        $this->assertCount(1, $rows);
        $r = $rows->first();
        $this->assertSame([$second->id, 'NPBG/NA/26/X/050', '2026-10-19', 'BAN DALAM 7.50-16', 2.0, 'PCS', 2, 'PENDING', 'NPBG', $user->id],
            [$r->npbg_id, $r->npbg_no, substr((string) $r->npbg_date, 0, 10), $r->description, (float) $r->qty, $r->unit, (int) $r->item_no, $r->status, $r->source, $r->created_by]);

        // list + detail show the tick; the filter narrows to ticked lines
        $list = collect($this->getJson('/api/npbg')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($list[$second->id]['kembali_bekas']);
        $this->assertFalse($list[$first->id]['kembali_bekas']);
        $this->assertSame([$second->id], collect($this->getJson('/api/npbg?kembali_bekas=1')->json('data'))->pluck('id')->all());
        $this->assertSame([$first->id], collect($this->getJson('/api/npbg?kembali_bekas=0')->json('data'))->pluck('id')->all());
        $this->getJson("/api/npbg/{$second->id}")->assertOk()->assertJsonPath('data.kembali_bekas_status', 'PENDING');

        // shows up in the Pengembalian Bekas sheet itself
        $this->assertSame(['NPBG/NA/26/X/050'], collect($this->getJson('/api/used-return-sheets/other')->assertOk()->json('data'))->pluck('npbg_no')->all());

        $this->putJson("/api/npbg/{$second->id}/kembali-bekas", ['checked' => false])->assertOk()->assertJsonPath('data.kembali_bekas', false);
        $this->assertSame(0, DB::table('used_return_other_rows')->count());
    }

    public function test_untick_is_refused_once_the_return_was_recorded(): void
    {
        $line = $this->line(0, 'BAN LUAR 7.50-16');
        $this->actingAsRole('admin_gudang');
        $this->putJson("/api/npbg/{$line->id}/kembali-bekas", ['checked' => true])->assertOk();
        DB::table('used_return_other_rows')->update(['ri_no' => 'RI/NA/26/X/001']);

        $this->putJson("/api/npbg/{$line->id}/kembali-bekas", ['checked' => false])->assertStatus(422);
        $this->assertSame(1, DB::table('used_return_other_rows')->count());
    }

    public function test_tambah_npbg_with_kembali_bekas_ticked_goes_straight_into_tracking(): void
    {
        $this->actingAsRole('admin_gudang');
        $base = ['pelanggan' => 'MAINTENANCE (SDA)', 'tipe_npbg' => 'Non Penjualan', 'deskripsi_barang' => 'BAN LUAR 7.50-16', 'kuantitas' => 1, 'satuan' => 'PCS'];

        $made = $this->postJson('/api/npbg', $base + ['kembali_bekas' => true])->assertCreated()
            ->assertJsonPath('data.kembali_bekas', true)->assertJsonPath('data.kembali_bekas_status', 'PENDING')->json('data');
        $row = DB::table('used_return_other_rows')->first();
        $this->assertSame([$made['id'], $made['no_npbg'], 'BAN LUAR 7.50-16', 1, 'NPBG'], [$row->npbg_id, $row->npbg_no, $row->description, (int) $row->item_no, $row->source]);

        // not ticked = nothing tracked
        $this->postJson('/api/npbg', $base + ['allow_duplicate' => true])->assertCreated()->assertJsonPath('data.kembali_bekas', false);
        $this->assertSame(1, DB::table('used_return_other_rows')->count());
    }

    public function test_tambah_npbg_ticked_without_used_return_access_is_refused_and_creates_nothing(): void
    {
        $user = $this->actingAsRole('bos');
        $user->roles()->first()->permissions()->attach(\App\Models\Permission::where('slug', 'npbg.create')->firstOrFail());
        $this->postJson('/api/npbg', ['pelanggan' => 'X', 'tipe_npbg' => 'Penjualan', 'deskripsi_barang' => 'Y', 'kuantitas' => 1, 'kembali_bekas' => true])
            ->assertForbidden();
        $this->assertSame(0, Npbg::count());
    }

    public function test_requires_used_return_permission(): void
    {
        $line = $this->line(0, 'BAN LUAR 7.50-16');
        $this->actingAsRole('bos');
        $this->putJson("/api/npbg/{$line->id}/kembali-bekas", ['checked' => true])->assertForbidden();
        $this->assertSame(0, DB::table('used_return_other_rows')->count());
    }
}
