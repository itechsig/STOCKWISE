<?php

namespace Tests\Feature\Accurate;

use App\Models\Npbg;
use App\Models\SyncBatch;
use App\Support\SyncDiff;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Accurate changed a document number (NPBG ATK/26/IX/046 → ATK/26/X/001, user
 * report 2026-10-01): the sync follows it by ARINVOICEID, the tracking copies of
 * the number follow, and the sync history says what changed, before → after.
 */
class SyncChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('accurate_arinv', function ($t) {
            foreach (['INVOICENO', 'PURCHASEORDERNO', 'SHIPTO1', 'DESCRIPTION'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('ARINVOICEID')->nullable();
            foreach (['INVOICEDATE', 'SHIPDATE', 'TAXDATE'] as $c) {
                $t->date($c)->nullable();
            }
        });
        Schema::create('accurate_arinvdet', function ($t) {
            $t->integer('ARINVOICEID')->nullable();
            $t->integer('SEQ')->nullable();
            $t->string('ITEMNO')->nullable();
            $t->string('ITEMOVDESC')->nullable();
            $t->decimal('QUANTITY', 14, 2)->nullable();
            $t->string('ITEMUNIT')->nullable();
            $t->string('ITEMRESERVED1')->nullable();
            $t->decimal('UNITRATIO', 14, 4)->nullable();
        });
        Process::fake();
        $this->actingAsRole('admin_gudang');
    }

    private function stageNpbg(int $id, string $no, string $date): void
    {
        DB::table('accurate_arinv')->insert(['ARINVOICEID' => $id, 'INVOICENO' => $no, 'INVOICEDATE' => $date, 'PURCHASEORDERNO' => 'GUDANG', 'SHIPTO1' => 'W 1 A']);
        DB::table('accurate_arinvdet')->insert([
            ['ARINVOICEID' => $id, 'SEQ' => 1, 'ITEMNO' => 'BAN.0001', 'ITEMOVDESC' => 'BAN 750-16', 'QUANTITY' => 1, 'ITEMUNIT' => 'PCS', 'ITEMRESERVED1' => 'BUDI', 'UNITRATIO' => 1],
            ['ARINVOICEID' => $id, 'SEQ' => 2, 'ITEMNO' => 'OFN.0118', 'ITEMOVDESC' => 'KERTAS', 'QUANTITY' => 2, 'ITEMUNIT' => 'RIM', 'ITEMRESERVED1' => 'BUDI', 'UNITRATIO' => 1],
        ]);
    }

    private function sync(): array
    {
        return $this->postJson('/api/sync/accurate')->assertCreated()->json('data');
    }

    public function test_renumbered_npbg_follows_into_tracking_and_the_history_shows_it(): void
    {
        $this->stageNpbg(4353, 'ATK/26/IX/046', '2026-09-30');
        $this->stageNpbg(4354, 'ATK/26/IX/047', '2026-09-30');
        $this->sync();
        $line = Npbg::where('accurate_arinvoice_id', 4353)->where('accurate_seq', 1)->firstOrFail();

        // tracking rows typed from the Excel
        $tyre = DB::table('tyre_changes')->insertGetId(['change_date' => '2026-09-30', 'nopol_raw' => 'W 1 A', 'out_npbg_id' => $line->id, 'out_npbg_no' => 'ATK/26/IX/046', 'status' => 'CLEAR', 'source' => 'EXCEL']);
        $lend = DB::table('lend_transactions')->insertGetId(['number' => 'LEND/1', 'description_raw' => 'X', 'qty' => 1, 'out_date' => '2026-09-30',
            'out_npbg_id' => $line->id, 'out_doc_no' => 'ATK/26/IX/046 & ATK/26/IX/047', 'status' => 'ON_LOAN']);
        $textOnly = DB::table('used_return_other_rows')->insertGetId(['npbg_no' => 'ATK/26/IX/046', 'status' => 'PENDING']);
        $other = DB::table('used_return_other_rows')->insertGetId(['npbg_no' => 'ATK/26/IX/0461', 'status' => 'PENDING']);

        // Accurate moves the date to October → new number
        DB::table('accurate_arinv')->where('ARINVOICEID', 4353)->update(['INVOICENO' => 'ATK/26/X/001', 'INVOICEDATE' => '2026-10-01']);
        $batch = $this->sync();

        $this->assertSame(['ATK/26/X/001'], Npbg::where('accurate_arinvoice_id', 4353)->pluck('no_npbg')->unique()->values()->all());
        $this->assertSame('ATK/26/X/001', DB::table('tyre_changes')->find($tyre)->out_npbg_no);
        $this->assertSame('ATK/26/X/001 & ATK/26/IX/047', DB::table('lend_transactions')->find($lend)->out_doc_no, 'only the renumbered token');
        $this->assertSame('ATK/26/X/001', DB::table('used_return_other_rows')->find($textOnly)->npbg_no, 'old number no longer used → text-only copy follows');
        $this->assertSame('ATK/26/IX/0461', DB::table('used_return_other_rows')->find($other)->npbg_no, 'a longer number is another document');

        // history: the document line shows the old and new number, the fields that changed, and the tracking rows
        $detail = $this->getJson("/api/sync/history/{$batch['id']}")->assertOk()->json('data');
        $npbgLog = collect($detail['logs'])->firstWhere('entity', 'npbg');
        $this->assertSame(['ATK/26/IX/046', 'ATK/26/X/001'], [$npbgLog['document_old'], $npbgLog['document']]);
        $this->assertStringContainsString('ATK/26/IX/046 → ATK/26/X/001 (nomor berubah)', $npbgLog['message']);
        $this->assertEqualsCanonicalizing(
            [['Nomor NPBG', 'ATK/26/IX/046', 'ATK/26/X/001'], ['Tanggal NPBG', '2026-09-30', '2026-10-01']],
            collect($npbgLog['changes'])->map(fn ($c) => [$c['label'], $c['from'], $c['to']])->all(),
        );
        $renumbers = collect($detail['logs'])->where('entity', 'renumber');
        $this->assertCount(3, $renumbers);
        $this->assertContains('Ban Luar', $renumbers->pluck('entity_label')->all());
        $this->assertSame(2, collect($detail['logs'])->where('entity', 'npbg')->count(), 'both lines of the renumbered NPBG, not the other document');

        // filter by number
        $found = $this->getJson("/api/sync/history/{$batch['id']}?entity=npbg&search=X/001")->assertOk()->json('data.logs');
        $this->assertCount(2, $found);
    }

    public function test_an_unchanged_resync_logs_no_update(): void
    {
        $this->stageNpbg(10, 'NA/26/IX/001', '2026-09-01');
        $this->sync();
        $second = $this->sync();

        $this->assertSame(0, $second['updated_records'], 'unit_ratio 1.0000 vs 1 is not a change');
        $this->assertSame(0, DB::table('sync_logs')->where('sync_batch_id', $second['id'])->where('action', 'UPDATE')->count());
    }

    public function test_history_hides_old_updates_without_a_real_change(): void
    {
        $batch = SyncBatch::create(['sync_code' => 'SYNC-T-1', 'source' => 'accurate', 'status' => 'SUCCESS', 'started_at' => now()]);
        $log = fn (array $old, array $new) => DB::table('sync_logs')->insert(['sync_batch_id' => $batch->id, 'entity' => 'npbg', 'source_id' => '1-1', 'action' => 'UPDATE',
            'status' => 'SUCCESS', 'message' => 'NPBG X baris 1 diperbarui.', 'old_data' => json_encode($old), 'new_data' => json_encode($new), 'created_at' => now()]);
        $log(['no_npbg' => 'X', 'unit_ratio' => '1.0000', 'kuantitas' => '2.00'], ['no_npbg' => 'X', 'unit_ratio' => 1, 'kuantitas' => 2]);
        $log(['no_npbg' => 'X', 'kuantitas' => '2.00'], ['no_npbg' => 'X', 'kuantitas' => 3]);

        $d = $this->getJson("/api/sync/history/{$batch->id}")->assertOk()->json('data');
        $this->assertSame([1, 1], [$d['log_total'], $d['log_no_change']]);
        $this->assertSame([['field' => 'kuantitas', 'label' => 'Kuantitas', 'from' => '2', 'to' => '3']], $d['logs'][0]['changes']);
    }

    public function test_compare_rule(): void
    {
        $this->assertTrue(SyncDiff::same('1.0000', 1));
        $this->assertTrue(SyncDiff::same('10.00', 10.0));
        $this->assertTrue(SyncDiff::same(null, ''));
        $this->assertTrue(SyncDiff::same(true, 1));
        $this->assertTrue(SyncDiff::same(\Illuminate\Support\Carbon::parse('2026-10-01'), '2026-10-01'));
        $this->assertTrue(SyncDiff::same('2026-10-01 00:00:00', '2026-10-01'));
        $this->assertFalse(SyncDiff::same('001', '1'), 'codes / numbers with a leading zero stay text');
        $this->assertFalse(SyncDiff::same('ATK/26/IX/046', 'ATK/26/X/001'));
        $this->assertFalse(SyncDiff::same(2, 2.5));

        $lines = SyncDiff::lineChanges(2, 'MAI.0828', ['qty_received' => '0.00', 'qty' => '1.00'], ['qty_received' => 1.0, 'qty' => 1.0]);
        $c = SyncDiff::changes(['status' => 'SENT'], ['status' => 'SENT', '_lines' => $lines]);
        $this->assertSame([['field' => 'line.2.qty_received', 'label' => 'Baris 2 (MAI.0828) · Qty diterima', 'from' => '0', 'to' => '1']], $c);
    }
}
