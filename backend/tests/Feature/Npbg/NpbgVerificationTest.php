<?php

namespace Tests\Feature\Npbg;

use App\Models\Npbg;
use App\Models\NpbgVerificationLog;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klarifikasi/Verifikasi Barang lifecycle — brief: Maintenance terima/tolak
 * barang alternatif, tolak wajib alasan, bisa eskalasi ke BOS, BOS memutuskan,
 * semua tercatat sebagai audit trail yang tidak bisa diubah/dihapus.
 */
class NpbgVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private function makeNpbg(): Npbg
    {
        return Npbg::create([
            'accurate_arinvoice_id' => random_int(1, 999999),
            'accurate_seq' => 1,
            'no_npbg' => 'ATK/26/IX/001',
            'tgl_npbg' => now()->toDateString(),
            'deskripsi_barang' => 'BOLT M10',
            'kuantitas' => 10,
            'satuan' => 'PCS',
        ]);
    }

    public function test_full_flow_maintenance_accepts_alternative(): void
    {
        $npbg = $this->makeNpbg();

        $this->actingAsRole('admin_gudang');
        $v = $this->postJson("/api/npbg/{$npbg->id}/verifications", [
            'alasan_pengajuan' => 'Barang yang datang beda merk dari yang diminta',
        ])->assertCreated()->json('data');
        $this->assertSame('DIAJUKAN', $v['status']);

        $id = $v['id'];
        $this->postJson("/api/npbg-verifications/{$id}/process")->assertOk()->assertJsonPath('data.status', 'DIPROSES');

        $this->postJson("/api/npbg-verifications/{$id}/offer-alternative", [
            'deskripsi_alternatif' => 'BOLT M10 merk lain, spesifikasi setara',
        ])->assertOk()->assertJsonPath('data.status', 'MENUNGGU_RESPON');

        $res = $this->postJson("/api/npbg-verifications/{$id}/respond", ['decision' => 'ACCEPT'])
            ->assertOk();
        $res->assertJsonPath('data.status', 'SELESAI')
            ->assertJsonPath('data.respon_maintenance', 'ACCEPT');

        $logs = $this->getJson("/api/npbg-verifications/{$id}")->assertOk()->json('data.logs');
        $actions = array_column($logs, 'action');
        $this->assertSame(['OPEN', 'PROCESS', 'OFFER_ALTERNATIVE', 'AWAIT_RESPONSE', 'RESPOND_ACCEPT', 'CLOSE'], $actions);
    }

    public function test_rejection_requires_reason_and_escalates_to_bos(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'Beda spek'])
            ->json('data.id');
        $this->postJson("/api/npbg-verifications/{$id}/process");
        $this->postJson("/api/npbg-verifications/{$id}/offer-alternative", ['deskripsi_alternatif' => 'Alternatif A']);

        // reject without reason -> rejected
        $this->postJson("/api/npbg-verifications/{$id}/respond", ['decision' => 'REJECT'])
            ->assertStatus(422);

        $res = $this->postJson("/api/npbg-verifications/{$id}/respond", [
            'decision' => 'REJECT', 'reason' => 'Alternatif tidak sesuai standar keselamatan',
        ])->assertOk();
        $res->assertJsonPath('data.status', 'PERLU_VERIFIKASI_BOS');

        $this->actingAsRole('bos');
        $this->postJson("/api/npbg-verifications/{$id}/bos-decide", [
            'keputusan' => 'DITOLAK', 'catatan' => 'Tetap gunakan barang asli, request ulang ke Purchasing',
        ])->assertOk()
            ->assertJsonPath('data.status', 'SELESAI')
            ->assertJsonPath('data.keputusan_bos', 'DITOLAK');
    }

    public function test_manual_escalate_and_bos_approve(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'Perlu keputusan cepat'])
            ->json('data.id');

        $this->postJson("/api/npbg-verifications/{$id}/escalate", ['note' => 'Langsung ke BOS, tidak lewat Maintenance'])
            ->assertOk()->assertJsonPath('data.status', 'PERLU_VERIFIKASI_BOS');

        $this->actingAsRole('bos');
        $this->postJson("/api/npbg-verifications/{$id}/bos-decide", ['keputusan' => 'DISETUJUI'])
            ->assertOk()->assertJsonPath('data.status', 'SELESAI');
    }

    public function test_cannot_open_second_verification_while_one_is_active(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'Kasus 1'])->assertCreated();

        $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'Kasus 2'])
            ->assertStatus(422);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'x'])->json('data.id');

        // can't respond before an alternative was even offered
        $this->postJson("/api/npbg-verifications/{$id}/respond", ['decision' => 'ACCEPT'])->assertStatus(422);

        // can't bos-decide before escalation
        $this->actingAsRole('bos');
        $this->postJson("/api/npbg-verifications/{$id}/bos-decide", ['keputusan' => 'DISETUJUI'])->assertStatus(422);
    }

    public function test_maintenance_response_requires_dedicated_permission(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'x'])->json('data.id');
        $this->postJson("/api/npbg-verifications/{$id}/process");
        $this->postJson("/api/npbg-verifications/{$id}/offer-alternative", ['deskripsi_alternatif' => 'y']);

        $this->actingAsRole('karyawan');
        $this->postJson("/api/npbg-verifications/{$id}/respond", ['decision' => 'ACCEPT'])->assertForbidden();
    }

    public function test_only_bos_can_decide(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'x'])->json('data.id');
        $this->postJson("/api/npbg-verifications/{$id}/escalate");

        $this->postJson("/api/npbg-verifications/{$id}/bos-decide", ['keputusan' => 'DISETUJUI'])
            ->assertForbidden(); // admin_gudang itself is not allowed to make the BOS decision
    }

    public function test_audit_log_cannot_be_updated_or_deleted(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $this->postJson("/api/npbg/{$npbg->id}/verifications", ['alasan_pengajuan' => 'x'])->assertCreated();

        $log = NpbgVerificationLog::first();
        $this->expectException(\RuntimeException::class);
        $log->update(['note' => 'diubah diam-diam']);
    }

    public function test_attachment_upload_and_download(): void
    {
        $npbg = $this->makeNpbg();
        $this->actingAsRole('admin_gudang');
        $png = 'data:image/png;base64,'.base64_encode('fake-image-bytes');

        $id = $this->postJson("/api/npbg/{$npbg->id}/verifications", [
            'alasan_pengajuan' => 'Ada bukti foto', 'attachment' => $png,
        ])->json('data.id');

        $detail = $this->getJson("/api/npbg-verifications/{$id}")->assertOk()->json('data');
        $this->assertTrue($detail['logs'][0]['has_attachment']);

        $this->get($detail['logs'][0]['attachment_url'])->assertOk();
    }
}
