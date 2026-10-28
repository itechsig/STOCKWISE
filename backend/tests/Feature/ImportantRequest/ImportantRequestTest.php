<?php

namespace Tests\Feature\ImportantRequest;

use App\Models\ImportantRequest;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Important Request: instruction → confirm → work → discuss → submit → review / revise → done. */
class ImportantRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private User $boss;

    private User $finance;

    private User $gudang;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->other = $this->actingAsRole('karyawan', ['name' => 'ORANG LAIN', 'username' => 'lain']);
        $this->gudang = $this->actingAsRole('admin_gudang', ['name' => 'ADMIN GUDANG', 'username' => 'gudang1']);
        $this->finance = $this->actingAsRole('karyawan', ['name' => 'FINANCE', 'username' => 'finance1']);
        $this->boss = $this->actingAsRole('direktur', ['name' => 'PAK DIREKTUR', 'username' => 'direktur1']);
    }

    private function as(User $u): static
    {
        app('auth')->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    /** a file uploaded in chunks → its token */
    private function upload(string $name, string $bytes, int $chunk = 4): string
    {
        $token = $this->postJson('/api/important-request-uploads', ['name' => $name, 'size' => strlen($bytes)])->assertCreated()->json('data.token');
        for ($o = 0; $o < strlen($bytes); $o += $chunk) {
            $this->call('POST', "/api/important-request-uploads/{$token}?offset={$o}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $o, $chunk))->assertOk();
        }

        return $token;
    }

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private function create(array $extra = []): array
    {
        $this->as($this->boss);

        return $this->postJson('/api/important-requests', $extra + [
            'title' => 'Perbaiki formula penjualan Oktober', 'description' => 'Periksa formula sheet Oktober, pastikan total sesuai.',
            'priority' => 'HIGH', 'deadline' => now()->addDays(3)->toDateTimeString(), 'assignee_ids' => [$this->finance->id, $this->gudang->id],
        ])->assertCreated()->json('data');
    }

    public function test_the_whole_flow_individual_with_files_discussion_review_and_audit(): void
    {
        $this->as($this->boss);
        // files: checked by extension, real content and size
        $this->postJson('/api/important-request-uploads', ['name' => 'virus.exe', 'size' => 10])->assertStatus(422);
        $this->postJson('/api/important-request-uploads', ['name' => 'besar.pdf', 'size' => 21 * 1024 * 1024])->assertStatus(422);
        $fake = $this->upload('palsu.pdf', '<html><script>alert(1)</script></html>');
        $this->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y', 'attachments' => [$fake]])->assertStatus(422)->assertJsonValidationErrors('attachments');
        // not oneself, not a stranger to the module
        $this->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y', 'assignee_ids' => [$this->boss->id]])->assertStatus(422)->assertJsonValidationErrors('assignee_ids');

        $pdf = $this->upload('referensi.pdf', self::PDF, 16);
        $draft = $this->create(['attachments' => [$pdf]]);
        $id = $draft['id'];
        $this->assertSame(['DRAFT', 1], [$draft['status'], count($draft['attachments'])]);
        $this->assertMatchesRegularExpression('#^IMP/\d{2}/[IVX]+/001$#', $draft['number']);
        // a draft is only its maker's
        $this->as($this->finance)->getJson("/api/important-requests/{$id}")->assertForbidden();

        $this->as($this->boss)->postJson("/api/important-requests/{$id}/send")->assertOk()->assertJsonPath('data.status', 'MENUNGGU_KONFIRMASI');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->finance->id)->count(), 'the recipient is told');

        // strangers see nothing, not even the file
        $file = $draft['attachments'][0]['id'];
        $this->as($this->other)->getJson("/api/important-requests/{$id}")->assertForbidden();
        $this->get("/api/important-requests/{$id}/attachments/{$file}")->assertForbidden();
        $this->as($this->finance)->get("/api/important-requests/{$id}/attachments/{$file}")->assertOk();

        // finance: confirm, start, progress; a recipient cannot review / cancel
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'x'])->assertStatus(422); // not confirmed yet
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'confirm'])->assertOk()->assertJsonPath('data.me.assignee.status', 'DITERIMA');
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'progress', 'progress' => 40, 'note' => 'Data Oktober tidak cocok'])->assertOk()->assertJsonPath('data.me.assignee.progress', 40);
        $this->postJson("/api/important-requests/{$id}/cancel", ['reason' => 'x'])->assertForbidden();

        // discussion: comment, reply to it, a reply to the reply stays one level, @mention
        $c = $this->postJson("/api/important-requests/{$id}/comments", ['body' => 'Baik Pak, ada data tidak sesuai. @direktur1'])->assertCreated()->json('data.id');
        $this->as($this->boss);
        $r1 = $this->postJson("/api/important-requests/{$id}/comments", ['body' => 'Perbaiki sesuai referensi.', 'parent_id' => $c])->assertCreated()->json('data.id');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/comments", ['body' => 'Siap.', 'parent_id' => $r1])->assertCreated();
        $threads = $this->getJson("/api/important-requests/{$id}/comments")->assertOk()->json('data');
        $mine = collect($threads)->firstWhere('id', $c);
        $this->assertCount(2, $mine['replies']);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->boss->id)->where('data', 'like', '%menyebut Anda%')->exists());
        // edit own; another's: no
        $this->putJson("/api/important-requests/{$id}/comments/{$c}", ['body' => 'Baik Pak (diperbarui).'])->assertOk();
        $this->putJson("/api/important-requests/{$id}/comments/{$r1}", ['body' => 'hack'])->assertForbidden();

        // submit the result with a file → waits for review
        $result = $this->upload('hasil.pdf', self::PDF);
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'Sudah diperbaiki', 'attachments' => [$result]])
            ->assertOk()->assertJsonPath('data.status', 'MENUNGGU_REVIEW')->assertJsonPath('data.me.assignee.status', 'MENUNGGU_REVIEW');
        $sub = ImportantRequest::find($id)->submissions()->sole();
        // the recipient (no create right) cannot review their own result
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => true])->assertForbidden();

        // the boss asks a revision (with a reason), finance sends round 2, the boss approves
        $this->as($this->boss);
        $this->getJson('/api/important-requests?scope=review')->assertJsonCount(1, 'data');
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => false, 'note' => 'Total November ikut berubah'])->assertOk()->assertJsonPath('data.status', 'REVISI');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'start'])->assertOk();
        $rev = $this->upload('hasil-v2.pdf', self::PDF);
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'attachments' => [$rev]])->assertOk();
        $sub2 = ImportantRequest::find($id)->submissions()->orderByDesc('id')->first();
        $this->assertSame([2, 'REVISION'], [$sub2->round, $sub2->attachments()->sole()->kind]);
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/submissions/{$sub2->id}/review", ['approve' => true, 'note' => 'OK'])->assertOk();

        // individual: still open until admin gudang's part is done too
        $this->assertSame('MENUNGGU_KONFIRMASI', ImportantRequest::find($id)->status);
        $this->as($this->gudang)->postJson("/api/important-requests/{$id}/act", ['action' => 'confirm'])->assertOk();
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'Data stok sudah dicek'])->assertOk();
        $last = ImportantRequest::find($id)->submissions()->orderByDesc('id')->first();
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/submissions/{$last->id}/review", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'SELESAI');
        // done: no more work on it
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'progress', 'progress' => 10])->assertStatus(422);
        $this->getJson('/api/important-requests?scope=done')->assertJsonCount(1, 'data');

        // the audit trail has every step
        $actions = ImportantRequest::find($id)->activities()->pluck('action')->all();
        foreach (['CREATED', 'SENT', 'CONFIRMED', 'PROGRESS', 'COMMENTED', 'REPLIED', 'COMMENT_EDITED', 'SUBMITTED', 'REVISION_REQUESTED', 'APPROVED', 'COMPLETED'] as $a) {
            $this->assertContains($a, $actions);
        }
        // reopen by its creator, with a reason
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/reopen", ['reason' => 'Ada sheet Desember'])->assertOk()->assertJsonPath('data.status', 'REVISI');
    }

    public function test_collective_one_result_for_all_and_no_approval_completes_at_once(): void
    {
        $r = $this->create(['assignment_mode' => 'COLLECTIVE', 'send' => true]);
        $this->as($this->finance)->postJson("/api/important-requests/{$r['id']}/act", ['action' => 'confirm']);
        $this->postJson("/api/important-requests/{$r['id']}/act", ['action' => 'submit', 'note' => 'hasil bersama'])->assertOk();
        $statuses = ImportantRequest::find($r['id'])->assignees()->pluck('status')->unique()->values()->all();
        $this->assertSame(['MENUNGGU_REVIEW'], $statuses, 'one result moves everyone');
        $sub = ImportantRequest::find($r['id'])->submissions()->sole();
        $this->as($this->boss)->postJson("/api/important-requests/{$r['id']}/submissions/{$sub->id}/review", ['approve' => true])->assertJsonPath('data.status', 'SELESAI');

        $auto = $this->create(['requires_approval' => false, 'send' => true]);
        $this->as($this->finance)->postJson("/api/important-requests/{$auto['id']}/act", ['action' => 'confirm']);
        $this->postJson("/api/important-requests/{$auto['id']}/act", ['action' => 'submit', 'note' => 'selesai'])->assertOk()->assertJsonPath('data.me.assignee.status', 'SELESAI');
        $this->assertSame('OTOMATIS', ImportantRequest::find($auto['id'])->submissions()->sole()->status);
    }

    public function test_blocker_extension_deadline_overdue_cancel_and_the_dashboard(): void
    {
        $r = $this->create(['send' => true]);
        $id = $r['id'];
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'confirm']);
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'blocker'])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'blocker', 'reason' => 'File sumber belum ada', 'proposed_deadline' => now()->addDays(7)->toDateTimeString()])
            ->assertOk()->assertJsonPath('data.status', 'TERKENDALA');
        $this->assertContains('EXTENSION_REQUESTED', ImportantRequest::find($id)->activities()->pluck('action')->all());

        // only its creator changes the deadline, with a reason
        $this->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7), 'reason' => 'x'])->assertForbidden();
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7)->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7)->toDateTimeString(), 'reason' => 'Menunggu file sumber'])->assertOk();

        // overdue: an indicator, the status stays; reminders once
        ImportantRequest::whereKey($id)->update(['deadline' => now()->subHour()]);
        $sum = $this->getJson('/api/important-requests/summary')->assertOk()->json('data');
        $this->assertSame([1, 1], [$sum['stats']['overdue'], $sum['stats']['blocked']]);
        $this->getJson('/api/important-requests/summary');
        $this->assertSame(1, ImportantRequest::find($id)->activities()->where('action', 'OVERDUE')->count(), 'told once');
        $this->getJson('/api/important-requests?overdue=1&scope=mine')->assertJsonCount(1, 'data')->assertJsonPath('data.0.overdue', true);

        // the badge: finance waits for nothing to confirm now; the boss has nothing to review
        $this->as($this->gudang)->getJson('/api/important-requests/summary')->assertJsonPath('data.action.to_confirm', 1);

        // cancel needs a reason; then nothing more can happen
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/cancel", [])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/cancel", ['reason' => 'Tidak jadi'])->assertOk()->assertJsonPath('data.status', 'DIBATALKAN');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'start'])->assertStatus(422);

        // analytics: only who may see it
        $this->getJson('/api/important-requests/analytics')->assertForbidden();
        $this->as($this->boss)->getJson('/api/important-requests/analytics')->assertOk()->assertJsonStructure(['data' => ['completion_rate', 'by_divisi', 'workload', 'trend']]);
        // a requester without the create right cannot make one
        $this->as($this->finance)->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y'])->assertForbidden();
    }
}
