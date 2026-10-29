<?php

namespace Tests\Feature\ImportantRequest;

use App\Models\ImportantRequest;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Important Request, like a Classroom assignment: assigned → handed in (on time / late) → approved (done) or returned for revision. */
class ImportantRequestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private User $boss;

    private User $finance;

    private User $gudang;

    private User $other;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

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
    private function upload(string $name, string $bytes, int $chunk = 16): string
    {
        $token = $this->postJson('/api/important-request-uploads', ['name' => $name, 'size' => strlen($bytes)])->assertCreated()->json('data.token');
        for ($o = 0; $o < strlen($bytes); $o += $chunk) {
            $this->call('POST', "/api/important-request-uploads/{$token}?offset={$o}", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($bytes, $o, $chunk))->assertOk();
        }

        return $token;
    }

    private function create(array $extra = []): array
    {
        $this->as($this->boss);

        return $this->postJson('/api/important-requests', $extra + [
            'title' => 'Perbaiki formula penjualan Oktober', 'description' => 'Periksa formula sheet Oktober, pastikan total sesuai.',
            'priority' => 'HIGH', 'deadline' => now()->addDays(3)->toDateTimeString(), 'assignee_ids' => [$this->finance->id, $this->gudang->id],
        ])->assertCreated()->json('data');
    }

    private function submission(int $id): \App\Models\ImportantRequestSubmission
    {
        return ImportantRequest::find($id)->submissions()->where('status', 'MENUNGGU_REVIEW')->latest('id')->firstOrFail();
    }

    public function test_assign_hand_in_return_hand_in_again_approve_done_with_files_and_discussion(): void
    {
        $this->as($this->boss);
        // files: checked by extension, real content and size
        $this->postJson('/api/important-request-uploads', ['name' => 'virus.exe', 'size' => 10])->assertStatus(422);
        $this->postJson('/api/important-request-uploads', ['name' => 'besar.pdf', 'size' => 21 * 1024 * 1024])->assertStatus(422);
        $fake = $this->upload('palsu.pdf', '<html><script>alert(1)</script></html>');
        $this->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y', 'attachments' => [$fake]])->assertStatus(422)->assertJsonValidationErrors('attachments');
        $this->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y', 'assignee_ids' => [$this->boss->id]])->assertStatus(422)->assertJsonValidationErrors('assignee_ids');

        $draft = $this->create(['attachments' => [$this->upload('referensi.pdf', self::PDF)]]);
        $id = $draft['id'];
        $this->assertSame(['DRAFT', 1, true], [$draft['status'], count($draft['attachments']), $draft['requires_approval']]);
        $this->assertMatchesRegularExpression('#^IMP/\d{2}/[IVX]+/001$#', $draft['number']);
        $this->as($this->finance)->getJson("/api/important-requests/{$id}")->assertForbidden(); // a draft is only its maker's

        $this->as($this->boss)->postJson("/api/important-requests/{$id}/send")->assertOk()->assertJsonPath('data.status', 'DITUGASKAN');
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->finance->id)->count(), 'the recipient is told');
        $file = $draft['attachments'][0]['id'];
        $this->as($this->other)->getJson("/api/important-requests/{$id}")->assertForbidden();
        $this->get("/api/important-requests/{$id}/attachments/{$file}")->assertForbidden();
        $this->as($this->finance)->get("/api/important-requests/{$id}/attachments/{$file}")->assertOk();
        $this->getJson('/api/important-requests/summary')->assertJsonPath('data.action.to_do', 1);

        // the old steps are gone; handing in needs a note or a file
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'confirm'])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit'])->assertStatus(422)->assertJsonValidationErrors('attachments');

        // discussion: comment, reply (one level), @mention, edit own only
        $c = $this->postJson("/api/important-requests/{$id}/comments", ['body' => 'Baik Pak, ada data tidak sesuai. @direktur1'])->assertCreated()->json('data.id');
        $this->as($this->boss);
        $r1 = $this->postJson("/api/important-requests/{$id}/comments", ['body' => 'Perbaiki sesuai referensi.', 'parent_id' => $c])->assertCreated()->json('data.id');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/comments", ['body' => 'Siap.', 'parent_id' => $r1])->assertCreated();
        $this->assertCount(2, collect($this->getJson("/api/important-requests/{$id}/comments")->json('data'))->firstWhere('id', $c)['replies']);
        $this->assertTrue(DB::table('notifications')->where('notifiable_id', $this->boss->id)->where('data', 'like', '%menyebut Anda%')->exists());
        $this->putJson("/api/important-requests/{$id}/comments/{$c}", ['body' => 'Baik Pak (diperbarui).'])->assertOk();
        $this->putJson("/api/important-requests/{$id}/comments/{$r1}", ['body' => 'hack'])->assertForbidden();

        // hand in before the deadline: "Diserahkan"; withdraw it, hand in again
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'v1', 'attachments' => [$this->upload('hasil.pdf', self::PDF)]])
            ->assertOk()->assertJsonPath('data.me.assignee.status', 'DISERAHKAN')->assertJsonPath('data.status', 'MENUNGGU_REVIEW');
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'unsubmit'])->assertOk()->assertJsonPath('data.me.assignee.status', 'DITUGASKAN');
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'Sudah diperbaiki'])->assertOk();
        $sub = $this->submission($id);
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => true])->assertForbidden(); // never one's own

        // returned for revision (a reason), handed in again, approved: that part is done
        $this->as($this->boss);
        $this->getJson('/api/important-requests?scope=review')->assertJsonCount(1, 'data');
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/important-requests/{$id}/submissions/{$sub->id}/review", ['approve' => false, 'note' => 'Total November ikut berubah'])->assertOk()->assertJsonPath('data.status', 'DITUGASKAN');
        $this->as($this->finance)->getJson("/api/important-requests/{$id}")->assertJsonPath('data.me.assignee.status', 'REVISI');
        $this->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'attachments' => [$this->upload('hasil-v2.pdf', self::PDF)]])->assertOk();
        $sub2 = $this->submission($id);
        $this->assertSame([2, 'REVISION'], [$sub2->round, $sub2->attachments()->sole()->kind]);
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/submissions/{$sub2->id}/review", ['approve' => true])->assertOk();
        // not done until every recipient's part is approved
        $this->assertSame('DITUGASKAN', ImportantRequest::find($id)->status);

        // admin gudang hands in after the deadline: "Diserahkan terlambat"
        ImportantRequest::whereKey($id)->update(['deadline' => now()->subHour()]);
        $this->as($this->boss)->getJson('/api/important-requests/summary')->assertJsonPath('data.stats.overdue', 1);
        $this->as($this->gudang)->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'Data stok sudah dicek'])
            ->assertOk()->assertJsonPath('data.me.assignee.status', 'DISERAHKAN_TERLAMBAT');
        $this->as($this->boss)->getJson('/api/important-requests/summary')->assertJsonPath('data.stats.overdue', 0); // everything handed in
        $last = $this->submission($id);
        $this->postJson("/api/important-requests/{$id}/submissions/{$last->id}/review", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'SELESAI');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'x'])->assertStatus(422);
        $this->getJson('/api/important-requests?scope=done')->assertJsonCount(1, 'data');

        $actions = ImportantRequest::find($id)->activities()->pluck('action')->all();
        foreach (['CREATED', 'SENT', 'COMMENTED', 'REPLIED', 'COMMENT_EDITED', 'SUBMITTED', 'UNSUBMITTED', 'REVISION_REQUESTED', 'APPROVED', 'COMPLETED'] as $a) {
            $this->assertContains($a, $actions);
        }
        // reopened by its creator, with a reason: back to revision
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/reopen", ['reason' => 'Ada sheet Desember'])->assertOk()->assertJsonPath('data.status', 'DITUGASKAN');
    }

    public function test_deadline_late_reminders_cancel_analytics_and_who_may_create(): void
    {
        $r = $this->create(['send' => true]);
        $id = $r['id'];
        // only its creator changes the deadline, with a reason
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7), 'reason' => 'x'])->assertForbidden();
        $this->as($this->boss)->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7)->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/deadline", ['deadline' => now()->addDays(7)->toDateTimeString(), 'reason' => 'Menunggu file sumber'])->assertOk();

        // late: an indicator, the status stays; reminded once
        ImportantRequest::whereKey($id)->update(['deadline' => now()->subHour()]);
        $this->getJson('/api/important-requests/summary')->assertOk()->assertJsonPath('data.stats.overdue', 1);
        $this->getJson('/api/important-requests/summary');
        $this->assertSame(1, ImportantRequest::find($id)->activities()->where('action', 'OVERDUE')->count(), 'told once');
        $this->getJson("/api/important-requests/{$id}")->assertJsonPath('data.assignees.0.late', true)->assertJsonPath('data.status', 'DITUGASKAN');

        $this->postJson("/api/important-requests/{$id}/cancel", [])->assertStatus(422);
        $this->postJson("/api/important-requests/{$id}/cancel", ['reason' => 'Tidak jadi'])->assertOk()->assertJsonPath('data.status', 'DIBATALKAN');
        $this->as($this->finance)->postJson("/api/important-requests/{$id}/act", ['action' => 'submit', 'note' => 'x'])->assertStatus(422);

        $this->getJson('/api/important-requests/analytics')->assertForbidden();
        $this->as($this->boss)->getJson('/api/important-requests/analytics')->assertOk()->assertJsonStructure(['data' => ['completion_rate', 'by_divisi', 'workload', 'trend']]);
        $this->as($this->finance)->postJson('/api/important-requests', ['title' => 'x', 'description' => 'y'])->assertForbidden();
    }
}
