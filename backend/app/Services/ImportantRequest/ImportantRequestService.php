<?php

namespace App\Services\ImportantRequest;

use App\Models\ImportantRequest;
use App\Models\ImportantRequestActivity;
use App\Models\ImportantRequestAssignee;
use App\Models\ImportantRequestComment;
use App\Models\ImportantRequestSubmission;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The workflow of an Important Request — simple, like an assignment in Google Classroom.
 *
 *   recipient: DITUGASKAN → (submit) DISERAHKAN, or DISERAHKAN_TERLAMBAT after the deadline
 *              → the creator approves: SELESAI, or returns it: REVISI → submit again
 *              (a submission not reviewed yet may be withdrawn: back to DITUGASKAN / REVISI)
 *   request:   DRAFT → DITUGASKAN → MENUNGGU_REVIEW (a result waits) → SELESAI (every part approved)
 *              (+ DIBATALKAN). Not handed in after the deadline: "terlambat" — an indicator, not a status.
 *
 * Done only when approved: the creator reviews; a recipient never approves their own result.
 * Every recipient hands in their own work (one request, several people = an assignment to a class).
 */
class ImportantRequestService
{
    public const STATUS_LABELS = [
        'DRAFT' => 'Draft', 'DITUGASKAN' => 'Ditugaskan', 'MENUNGGU_REVIEW' => 'Perlu ditinjau', 'SELESAI' => 'Selesai', 'DIBATALKAN' => 'Dibatalkan',
        // a recipient's own part
        'DISERAHKAN' => 'Diserahkan', 'DISERAHKAN_TERLAMBAT' => 'Diserahkan terlambat', 'REVISI' => 'Perlu revisi',
    ];

    /** a recipient's part handed in, waiting for the review */
    public const HANDED_IN = ['DISERAHKAN', 'DISERAHKAN_TERLAMBAT'];

    /** a recipient's part still to hand in */
    public const TO_DO = ['DITUGASKAN', 'REVISI'];

    public function __construct(private DocumentNumberService $numbers, private ImportantRequestFiles $files) {}

    // ------------------------------------------------------------------ create / edit / send

    /** @param array{title:string,description:string,priority?:string,category?:?string,deadline?:?string,requires_approval?:bool,assignment_mode?:string,note?:?string,assignee_ids?:list<int>,attachments?:list<string>,send?:bool} $data */
    public function create(User $user, array $data): ImportantRequest
    {
        $ids = $this->recipients($user, $data['assignee_ids'] ?? []);
        $request = DB::transaction(function () use ($user, $data, $ids) {
            $r = ImportantRequest::create([
                'number' => $this->numbers->nextShort('IMP'),
                'title' => trim($data['title']), 'description' => trim($data['description']),
                'priority' => $data['priority'] ?? 'MEDIUM', 'category' => $data['category'] ?? null,
                'deadline' => $data['deadline'] ?? null, 'requires_approval' => true, 'assignment_mode' => 'INDIVIDUAL', 'note' => $data['note'] ?? null,
                'status' => 'DRAFT', 'created_by' => $user->id,
            ]);
            foreach ($ids as $id) {
                $r->assignees()->create(['user_id' => $id, 'status' => 'DITUGASKAN']);
            }
            $files = $this->files->claim($user, $data['attachments'] ?? [], $r, 'ORIGINAL');
            $this->log($r, $user, 'CREATED', ['assignees' => $this->names($ids), 'files' => array_map(fn ($f) => $f->original_name, $files)]);

            return $r;
        });

        return ! empty($data['send']) ? $this->send($user, $request) : $request;
    }

    /** A draft: everything; once sent: the content (logged, recipients told) and more recipients. */
    public function update(User $user, ImportantRequest $request, array $data): ImportantRequest
    {
        $this->assertCreator($user, $request);
        return DB::transaction(function () use ($user, $request, $data) {
            $r = ImportantRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertNotClosed($r, allowDraft: true);
            $fields = ['title', 'description', 'priority', 'category', 'note'];
            if ($r->status === 'DRAFT') {
                $fields = [...$fields, 'deadline'];
            }
            $changes = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data) && (string) $data[$f] !== (string) $r->{$f}) {
                    $changes[$f] = ['dari' => $f === 'description' ? null : $r->{$f}, 'ke' => $f === 'description' ? null : $data[$f]];
                    $r->{$f} = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
                }
            }
            $r->save();
            if (array_key_exists('assignee_ids', $data)) {
                $wanted = $this->recipients($user, $data['assignee_ids']);
                $have = $r->assignees()->pluck('user_id')->all();
                $added = array_values(array_diff($wanted, $have));
                $removed = array_values(array_diff($have, $wanted));
                if ($r->status !== 'DRAFT' && $removed) {
                    throw ValidationException::withMessages(['assignee_ids' => 'Penerima tidak bisa dihapus setelah request dikirim (bisa ditambah).']);
                }
                $r->assignees()->whereIn('user_id', $removed)->delete();
                foreach ($added as $id) {
                    $r->assignees()->create(['user_id' => $id, 'status' => 'DITUGASKAN']);
                }
                if ($added || $removed) {
                    $this->log($r, $user, 'ASSIGNEES_CHANGED', ['ditambah' => $this->names($added), 'dihapus' => $this->names($removed)]);
                    if ($r->status !== 'DRAFT') {
                        $this->notify($added, $r, 'warning', "Tugas baru: {$r->title}", "{$user->name} menugaskan {$r->number} kepada Anda.", $user->id);
                    }
                }
            }
            if ($changes) {
                $this->log($r, $user, 'EDITED', ['perubahan' => $changes]);
                if ($r->status !== 'DRAFT') {
                    $this->notify($this->assigneeIds($r), $r, 'info', "{$r->number} diubah", "{$user->name} mengubah ".implode(', ', array_keys($changes)).'.', $user->id);
                }
            }
            $this->recompute($r);

            return $r;
        });
    }

    public function send(User $user, ImportantRequest $request): ImportantRequest
    {
        $this->assertCreator($user, $request);
        $r = DB::transaction(function () use ($user, $request) {
            $r = ImportantRequest::lockForUpdate()->findOrFail($request->id);
            if ($r->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Request ini sudah dikirim.']);
            }
            if (! $r->assignees()->exists()) {
                throw ValidationException::withMessages(['assignee_ids' => 'Pilih minimal satu penerima.']);
            }
            if (! $r->deadline) {
                throw ValidationException::withMessages(['deadline' => 'Isi deadline sebelum mengirim.']);
            }
            $r->update(['status' => 'DITUGASKAN', 'sent_at' => now()]);
            $this->log($r, $user, 'SENT');

            return $r;
        });
        $this->notify($this->assigneeIds($r), $r, $r->priority === 'URGENT' ? 'warning' : 'info',
            ($r->priority === 'URGENT' ? 'URGENT: ' : '')."Tugas baru: {$r->title}", "{$user->name} · {$r->number} · deadline ".$r->deadline->timezone(config('app.timezone'))->format('d/m/Y H:i'), $user->id);

        return $r;
    }

    public function deleteDraft(User $user, ImportantRequest $request): void
    {
        $this->assertCreator($user, $request);
        if ($request->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Hanya draft yang bisa dihapus; request terkirim dibatalkan.']);
        }
        $paths = $request->attachments()->pluck('path');
        $request->delete();
        foreach ($paths as $p) {
            \Illuminate\Support\Facades\Storage::disk(ImportantRequestFiles::DISK)->delete($p);
        }
    }

    public function addOriginals(User $user, ImportantRequest $request, array $tokens): void
    {
        $this->assertCreator($user, $request);
        $this->assertNotClosed($request, allowDraft: true);
        DB::transaction(function () use ($user, $request, $tokens) {
            $files = $this->files->claim($user, $tokens, $request, 'ORIGINAL');
            $this->log($request, $user, 'FILE_UPLOADED', ['jenis' => 'ORIGINAL', 'files' => array_map(fn ($f) => $f->original_name, $files)]);
        });
        if ($request->status !== 'DRAFT') {
            $this->notify($this->assigneeIds($request), $request, 'info', "Lampiran baru di {$request->number}", "{$user->name} menambah lampiran.", $user->id);
        }
    }

    // ------------------------------------------------------------------ recipient steps

    /** submit (hand in: a note and / or files) | unsubmit (withdraw a hand-in not reviewed yet) */
    public function act(User $user, ImportantRequest $request, string $action, array $data = []): ImportantRequest
    {
        $notifyCreator = null;
        $r = DB::transaction(function () use ($user, $request, $action, $data, &$notifyCreator) {
            $r = ImportantRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertNotClosed($r);
            $a = ImportantRequestAssignee::where(['important_request_id' => $r->id, 'user_id' => $user->id])->lockForUpdate()->first();
            if (! $a) {
                abort(403, 'Anda bukan penerima request ini.');
            }
            if ($action === 'submit') {
                if (! in_array($a->status, self::TO_DO, true)) {
                    throw ValidationException::withMessages(['status' => 'Tugas ini sudah diserahkan.']);
                }
                $note = trim((string) ($data['note'] ?? ''));
                $tokens = $data['attachments'] ?? [];
                if ($note === '' && ! $tokens) {
                    throw ValidationException::withMessages(['attachments' => 'Lampirkan file hasil atau tulis catatan hasil pekerjaan.']);
                }
                $late = $r->deadline !== null && now()->greaterThan($r->deadline);
                $round = 1 + (int) ImportantRequestSubmission::where('assignee_id', $a->id)->where('status', '!=', 'DITARIK')->count();
                $sub = ImportantRequestSubmission::create([
                    'important_request_id' => $r->id, 'assignee_id' => $a->id, 'user_id' => $user->id, 'round' => $round,
                    'note' => $note ?: null, 'status' => 'MENUNGGU_REVIEW',
                ]);
                $files = $this->files->claim($user, $tokens, $r, $round > 1 ? 'REVISION' : 'SUBMISSION', submissionId: $sub->id);
                $a->update(['status' => $late ? 'DISERAHKAN_TERLAMBAT' : 'DISERAHKAN', 'progress' => 100, 'submitted_at' => now()]);
                $this->log($r, $user, 'SUBMITTED', ['putaran' => $round, 'terlambat' => $late, 'files' => array_map(fn ($f) => $f->original_name, $files)]);
                $notifyCreator = ['info', ($late ? 'Diserahkan terlambat: ' : 'Diserahkan: ').$r->number,
                    "{$user->name} menyerahkan hasil".($round > 1 ? ' revisi' : '').'. Tinjau dan setujui atau kembalikan.'];
            } elseif ($action === 'unsubmit') {
                $sub = ImportantRequestSubmission::where(['assignee_id' => $a->id, 'status' => 'MENUNGGU_REVIEW'])->lockForUpdate()->latest('id')->first();
                if (! $sub || ! in_array($a->status, self::HANDED_IN, true)) {
                    throw ValidationException::withMessages(['status' => 'Tidak ada hasil yang bisa ditarik (sudah ditinjau?).']);
                }
                $sub->update(['status' => 'DITARIK']);
                $returned = ImportantRequestSubmission::where('assignee_id', $a->id)->where('status', 'REVISI')->exists();
                $a->update(['status' => $returned ? 'REVISI' : 'DITUGASKAN', 'submitted_at' => null]);
                $this->log($r, $user, 'UNSUBMITTED', ['putaran' => $sub->round]);
            } else {
                abort(404);
            }
            $this->recompute($r);

            return $r;
        });
        if ($notifyCreator) {
            $this->notify([$r->created_by], $r, $notifyCreator[0], $notifyCreator[1], $notifyCreator[2], $user->id);
        }

        return $r;
    }

    // ------------------------------------------------------------------ creator: review, deadline, cancel, reopen

    public function review(User $user, ImportantRequest $request, ImportantRequestSubmission $submission, bool $approve, ?string $note): ImportantRequest
    {
        $this->assertCreator($user, $request, 'Hanya pembuat request yang meninjau hasilnya.');
        $who = [];
        $r = DB::transaction(function () use ($user, $request, $submission, $approve, $note, &$who) {
            $r = ImportantRequest::lockForUpdate()->findOrFail($request->id);
            $this->assertNotClosed($r);
            $sub = ImportantRequestSubmission::lockForUpdate()->findOrFail($submission->id);
            abort_unless($sub->important_request_id === $r->id, 404);
            if ($sub->status !== 'MENUNGGU_REVIEW') {
                throw ValidationException::withMessages(['status' => 'Hasil ini sudah ditinjau.']);
            }
            if ($sub->user_id === $user->id) {
                abort(403, 'Hasil sendiri tidak bisa disetujui sendiri.');
            }
            if (! $approve && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => 'Tulis apa yang perlu direvisi.']);
            }
            $sub->update(['status' => $approve ? 'DISETUJUI' : 'REVISI', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $note ? trim($note) : null]);
            $targets = $r->assignment_mode === 'COLLECTIVE'
                ? ImportantRequestAssignee::where('important_request_id', $r->id)->whereIn('status', self::HANDED_IN)->get()
                : ImportantRequestAssignee::whereKey($sub->assignee_id)->get();
            foreach ($targets as $t) {
                $t->update($approve ? ['status' => 'SELESAI', 'completed_at' => now()] : ['status' => 'REVISI', 'progress' => 0, 'submitted_at' => null]);
                $who[] = $t->user_id;
            }
            $this->log($r, $user, $approve ? 'APPROVED' : 'REVISION_REQUESTED', ['putaran' => $sub->round, 'catatan' => $note]);
            $this->recompute($r);

            return $r;
        });
        $this->notify($who, $r, $approve ? 'success' : 'warning', $approve ? "Hasil {$r->number} disetujui" : "Revisi diminta: {$r->number}",
            $approve ? "{$user->name} menyetujui hasil Anda." : "{$user->name}: {$note}", $user->id);

        return $r;
    }

    public function changeDeadline(User $user, ImportantRequest $request, string $deadline, string $reason): ImportantRequest
    {
        $this->assertCreator($user, $request);
        $this->assertNotClosed($request);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan perubahan deadline.']);
        }
        $old = $request->deadline;
        $request->update(['deadline' => Carbon::parse($deadline), 'reminded_soon_at' => null, 'reminded_overdue_at' => null]);
        $this->log($request, $user, 'DEADLINE_CHANGED', ['dari' => $old?->toIso8601String(), 'ke' => $request->deadline->toIso8601String(), 'alasan' => trim($reason)]);
        $this->notify($this->assigneeIds($request), $request, 'info', "Deadline {$request->number} diubah",
            'Menjadi '.$request->deadline->timezone(config('app.timezone'))->format('d/m/Y H:i').": {$reason}", $user->id);

        return $request;
    }

    public function cancel(User $user, ImportantRequest $request, string $reason): ImportantRequest
    {
        $this->assertCreator($user, $request);
        $this->assertNotClosed($request);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan pembatalan.']);
        }
        $request->update(['status' => 'DIBATALKAN', 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 500)]);
        $this->log($request, $user, 'CANCELLED', ['alasan' => trim($reason)]);
        $this->notify($this->assigneeIds($request), $request, 'warning', "{$request->number} dibatalkan", "{$user->name}: {$reason}", $user->id);

        return $request;
    }

    /** Reopen a done or cancelled request (its creator, or who monitors all): a done one goes back to revision. */
    public function reopen(User $user, ImportantRequest $request, string $reason): ImportantRequest
    {
        if ($request->created_by !== $user->id && ! $user->hasPermission('important_request.view_all')) {
            abort(403, 'Hanya pembuat request (atau admin) yang bisa membuka kembali.');
        }
        if (! in_array($request->status, ['SELESAI', 'DIBATALKAN'], true)) {
            throw ValidationException::withMessages(['status' => 'Hanya request selesai / dibatalkan yang bisa dibuka kembali.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan membuka kembali.']);
        }
        DB::transaction(function () use ($user, $request, $reason) {
            $wasDone = $request->status === 'SELESAI';
            if ($wasDone) {
                $request->assignees()->update(['status' => 'REVISI', 'completed_at' => null]);
            }
            $request->update(['status' => 'DITUGASKAN', 'completed_at' => null, 'cancelled_at' => null, 'cancel_reason' => null]);
            $this->log($request, $user, 'REOPENED', ['alasan' => trim($reason)]);
            $this->recompute($request);
        });
        $this->notify($this->assigneeIds($request), $request, 'warning', "{$request->number} dibuka kembali", "{$user->name}: {$reason}", $user->id);

        return $request;
    }

    // ------------------------------------------------------------------ discussion

    /** A comment / reply (one level deep), its files, and @username mentions told. */
    public function addComment(User $user, ImportantRequest $request, string $body, ?int $parentId, array $tokens): ImportantRequestComment
    {
        if ($request->status === 'DRAFT') {
            throw ValidationException::withMessages(['body' => 'Kirim request dulu sebelum berdiskusi.']);
        }
        if (trim($body) === '' && ! $tokens) {
            throw ValidationException::withMessages(['body' => 'Tulis komentar atau lampirkan file.']);
        }
        if ($parentId) {
            $parent = ImportantRequestComment::where(['id' => $parentId, 'important_request_id' => $request->id])->first();
            abort_unless($parent, 404);
            // replies stay one level deep: a reply to a reply hangs under its parent
            $parentId = $parent->parent_id ?? $parent->id;
        }
        $c = DB::transaction(function () use ($user, $request, $body, $parentId, $tokens) {
            $c = $this->comment($user, $request, 'COMMENT', trim($body), $parentId);
            $files = $this->files->claim($user, $tokens, $request, 'DISCUSSION', commentId: $c->id);
            $this->log($request, $user, $parentId ? 'REPLIED' : 'COMMENTED', ['komentar' => $c->id, 'files' => array_map(fn ($f) => $f->original_name, $files)]);

            return $c;
        });
        $participants = $this->participants($request);
        $mentioned = [];
        if (preg_match_all('/@([A-Za-z0-9._-]{3,60})/', $body, $m)) {
            $mentioned = User::whereIn('username', array_unique($m[1]))->whereIn('id', $participants)->pluck('id')->all();
            $this->notify($mentioned, $request, 'info', "{$user->name} menyebut Anda di {$request->number}", mb_substr(trim($body), 0, 140), $user->id);
        }
        $others = array_values(array_diff($participants, $mentioned));
        $this->notify($others, $request, 'info', ($parentId ? 'Balasan' : 'Komentar')." baru di {$request->number}", "{$user->name}: ".mb_substr(trim($body), 0, 140), $user->id);

        return $c;
    }

    public function editComment(User $user, ImportantRequest $request, ImportantRequestComment $c, string $body): ImportantRequestComment
    {
        abort_unless($c->important_request_id === $request->id, 404);
        abort_unless($c->user_id === $user->id && $c->kind === 'COMMENT' && ! $c->deleted_at, 403, 'Hanya komentar sendiri yang bisa diubah.');
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Komentar tidak boleh kosong.']);
        }
        $this->log($request, $user, 'COMMENT_EDITED', ['komentar' => $c->id, 'sebelumnya' => mb_substr($c->body, 0, 500)]);
        $c->update(['body' => trim($body), 'edited_at' => now()]);

        return $c;
    }

    /** Deleted: its text is kept in the audit trail, the discussion shows "pesan dihapus". */
    public function deleteComment(User $user, ImportantRequest $request, ImportantRequestComment $c): void
    {
        abort_unless($c->important_request_id === $request->id, 404);
        abort_unless(($c->user_id === $user->id || $user->hasPermission('important_request.view_all')) && $c->kind === 'COMMENT', 403, 'Tidak bisa menghapus komentar ini.');
        $this->log($request, $user, 'COMMENT_DELETED', ['komentar' => $c->id, 'isi' => mb_substr($c->body, 0, 500)]);
        $c->update(['deleted_at' => now()]);
    }

    public function deleteAttachment(User $user, ImportantRequest $request, \App\Models\ImportantRequestAttachment $f): void
    {
        abort_unless($f->important_request_id === $request->id && ! $f->deleted_at, 404);
        abort_unless($f->uploaded_by === $user->id || $request->created_by === $user->id, 403, 'Hanya pengunggah atau pembuat request yang bisa menghapus file.');
        // a result already reviewed stays as it was reviewed
        if ($f->submission_id && \App\Models\ImportantRequestSubmission::whereKey($f->submission_id)->where('status', '!=', 'MENUNGGU_REVIEW')->exists()) {
            throw ValidationException::withMessages(['file' => 'File hasil yang sudah ditinjau tidak bisa dihapus.']);
        }
        $this->assertNotClosed($request, allowDraft: true);
        $f->update(['deleted_at' => now(), 'deleted_by' => $user->id]);
        $this->log($request, $user, 'FILE_DELETED', ['file' => $f->original_name, 'jenis' => $f->kind]);
    }

    // ------------------------------------------------------------------ reminders

    /** Deadline in 24 h / passed: recipients (and the creator when overdue) told once. */
    public function remind(): int
    {
        $n = 0;
        foreach (ImportantRequest::whereNotIn('status', ImportantRequest::CLOSED)->whereNotNull('deadline')->whereNull('reminded_soon_at')
            ->where('deadline', '>', now())->where('deadline', '<=', now()->addDay())->get() as $r) {
            $r->update(['reminded_soon_at' => now()]);
            $this->notify($this->openAssigneeIds($r), $r, 'warning', "Deadline {$r->number} besok", "{$r->title} · ".$r->deadline->timezone(config('app.timezone'))->format('d/m H:i'));
            $n++;
        }
        foreach (ImportantRequest::overdue()->whereNull('reminded_overdue_at')->get() as $r) {
            $r->update(['reminded_overdue_at' => now()]);
            $this->log($r, null, 'OVERDUE');
            $this->notify([...$this->openAssigneeIds($r), $r->created_by], $r, 'warning', "{$r->number} terlambat", "{$r->title} melewati deadline.");
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ helpers

    /** The request status from its recipients: done when every part is approved. */
    public function recompute(ImportantRequest $r): void
    {
        if (in_array($r->status, ['DRAFT', 'DIBATALKAN'], true)) {
            return;
        }
        $s = $r->assignees()->pluck('status');
        $next = match (true) {
            $s->isNotEmpty() && $s->every(fn ($x) => $x === 'SELESAI') => 'SELESAI',
            $s->contains(fn ($x) => in_array($x, self::HANDED_IN, true)) => 'MENUNGGU_REVIEW',
            default => 'DITUGASKAN',
        };
        if ($next !== $r->status) {
            $r->update(['status' => $next, 'completed_at' => $next === 'SELESAI' ? now() : null]);
            if ($next === 'SELESAI') {
                $this->log($r, null, 'COMPLETED');
            }
        }
    }

    /** People who may be given an Important Request: active accounts with access, never oneself. */
    public function recipients(User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (in_array($user->id, $ids, true)) {
            throw ValidationException::withMessages(['assignee_ids' => 'Anda tidak bisa menugaskan diri sendiri (hasil Anda yang meninjau).']);
        }
        $ok = self::assignableUsers()->whereIn('users.id', $ids)->pluck('users.id')->all();
        if (count($ok) !== count($ids)) {
            throw ValidationException::withMessages(['assignee_ids' => 'Ada penerima yang tidak aktif atau tidak punya akses Important Request.']);
        }

        return $ids;
    }

    public static function assignableUsers()
    {
        return User::query()->where('users.is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'important_request.access'));
    }

    public function canView(User $user, ImportantRequest $r): bool
    {
        if ($r->created_by === $user->id) {
            return true;
        }
        if ($r->status === 'DRAFT') {
            return false;
        }

        return $user->hasPermission('important_request.view_all') || $r->assignees()->where('user_id', $user->id)->exists();
    }

    /** creator + recipients (who discuss and are told) */
    public function participants(ImportantRequest $r): array
    {
        return array_values(array_unique([$r->created_by, ...$this->assigneeIds($r)]));
    }

    private function assigneeIds(ImportantRequest $r): array
    {
        return $r->assignees()->pluck('user_id')->all();
    }

    /** recipients who still have to hand in */
    private function openAssigneeIds(ImportantRequest $r): array
    {
        return $r->assignees()->whereIn('status', self::TO_DO)->pluck('user_id')->all();
    }

    private function comment(User $user, ImportantRequest $r, string $kind, string $body, ?int $parentId = null): ImportantRequestComment
    {
        return ImportantRequestComment::create(['important_request_id' => $r->id, 'parent_id' => $parentId, 'user_id' => $user->id, 'kind' => $kind, 'body' => $body]);
    }

    public function log(ImportantRequest $r, ?User $user, string $action, array $meta = []): void
    {
        ImportantRequestActivity::create(['important_request_id' => $r->id, 'user_id' => $user?->id, 'action' => $action, 'meta' => $meta ?: null, 'created_at' => now()]);
    }

    private function notify(array $userIds, ImportantRequest $r, string $level, string $title, string $body, ?int $except = null): void
    {
        foreach (array_unique(array_filter($userIds)) as $id) {
            if ($id !== $except) {
                NotificationDispatcher::toUser($id, 'important', $level, $title, $body, "/important-requests/{$r->id}");
            }
        }
    }

    private function names(array $ids): array
    {
        return $ids ? User::whereIn('id', $ids)->pluck('name')->all() : [];
    }

    private function assertCreator(User $user, ImportantRequest $r, string $msg = 'Hanya pembuat request yang bisa melakukan ini.'): void
    {
        abort_unless($r->created_by === $user->id, 403, $msg);
    }

    private function assertNotClosed(ImportantRequest $r, bool $allowDraft = false): void
    {
        if ($r->status === 'DRAFT' && ! $allowDraft) {
            throw ValidationException::withMessages(['status' => 'Request ini masih draft.']);
        }
        if (in_array($r->status, ['SELESAI', 'DIBATALKAN'], true)) {
            throw ValidationException::withMessages(['status' => 'Request ini sudah '.strtolower(self::STATUS_LABELS[$r->status]).'.']);
        }
    }
}
