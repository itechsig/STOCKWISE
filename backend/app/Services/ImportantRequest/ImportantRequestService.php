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
 * The workflow of an Important Request.
 *
 *   request:  DRAFT → MENUNGGU_KONFIRMASI → DITERIMA → IN_PROGRESS → MENUNGGU_REVIEW → SELESAI
 *             (+ TERKENDALA, REVISI, DIBATALKAN; overdue is an indicator, never a status)
 *   recipient: MENUNGGU_KONFIRMASI → DITERIMA → IN_PROGRESS ⇄ TERKENDALA → MENUNGGU_REVIEW → SELESAI / REVISI → IN_PROGRESS
 *
 * The creator reviews (approves or asks a revision); a recipient never approves their own result.
 * INDIVIDUAL: each recipient submits their own part, the request is done when every part is.
 * COLLECTIVE: one result for all — a submission by any recipient moves them all, so does its review.
 * Without required approval a submission completes the recipient's part at once (logged OTOMATIS).
 * The request status follows its recipients (recompute()).
 */
class ImportantRequestService
{
    public const STATUS_LABELS = [
        'DRAFT' => 'Draft', 'MENUNGGU_KONFIRMASI' => 'Menunggu konfirmasi', 'DITERIMA' => 'Diterima', 'IN_PROGRESS' => 'In progress',
        'TERKENDALA' => 'Terkendala', 'MENUNGGU_REVIEW' => 'Menunggu review', 'REVISI' => 'Revisi', 'SELESAI' => 'Selesai', 'DIBATALKAN' => 'Dibatalkan',
    ];

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
                'deadline' => $data['deadline'] ?? null, 'requires_approval' => $data['requires_approval'] ?? true,
                'assignment_mode' => $data['assignment_mode'] ?? 'INDIVIDUAL', 'note' => $data['note'] ?? null,
                'status' => 'DRAFT', 'created_by' => $user->id,
            ]);
            foreach ($ids as $id) {
                $r->assignees()->create(['user_id' => $id]);
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
                $fields = [...$fields, 'deadline', 'requires_approval', 'assignment_mode'];
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
                    $r->assignees()->create(['user_id' => $id]);
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
            $r->update(['status' => 'MENUNGGU_KONFIRMASI', 'sent_at' => now()]);
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

    /** confirm | start | progress | blocker | submit | extension */
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
            $need = fn (array $from, string $msg) => in_array($a->status, $from, true) ?: throw ValidationException::withMessages(['status' => $msg]);
            switch ($action) {
                case 'confirm':
                    $need(['MENUNGGU_KONFIRMASI'], 'Tugas ini sudah dikonfirmasi.');
                    $a->update(['status' => 'DITERIMA', 'confirmed_at' => now()]);
                    $this->log($r, $user, 'CONFIRMED');
                    $notifyCreator = ['info', "{$user->name} menerima {$r->number}", $r->title];
                    break;
                case 'start':
                    $need(['DITERIMA', 'REVISI', 'TERKENDALA'], 'Tugas ini tidak bisa dimulai dari status sekarang.');
                    $from = $a->status;
                    $a->update(['status' => 'IN_PROGRESS', 'started_at' => $a->started_at ?? now(), 'blocker_note' => $from === 'TERKENDALA' ? null : $a->blocker_note]);
                    $this->log($r, $user, $from === 'TERKENDALA' ? 'RESUMED' : 'STARTED');
                    if ($from === 'TERKENDALA') {
                        $notifyCreator = ['info', "{$r->number} dilanjutkan", "{$user->name}: kendala teratasi, pekerjaan dilanjutkan."];
                    }
                    break;
                case 'progress':
                    $need(['DITERIMA', 'IN_PROGRESS', 'REVISI'], 'Update progres hanya saat tugas dikerjakan.');
                    $p = max(0, min(100, (int) ($data['progress'] ?? $a->progress)));
                    $a->update(['progress' => $p, 'status' => 'IN_PROGRESS', 'started_at' => $a->started_at ?? now()]);
                    $this->comment($user, $r, 'PROGRESS', "Progres {$p}%".(filled($data['note'] ?? null) ? ': '.trim($data['note']) : ''));
                    $this->log($r, $user, 'PROGRESS', ['progress' => $p]);
                    $notifyCreator = ['info', "Progres {$r->number}: {$p}%", "{$user->name}".(filled($data['note'] ?? null) ? ': '.trim($data['note']) : '')];
                    break;
                case 'blocker':
                    $need(['DITERIMA', 'IN_PROGRESS', 'REVISI'], 'Kendala hanya dilaporkan saat tugas dikerjakan.');
                    $reason = trim((string) ($data['reason'] ?? ''));
                    $reason !== '' ?: throw ValidationException::withMessages(['reason' => 'Tulis kendalanya.']);
                    $a->update(['status' => 'TERKENDALA', 'blocker_note' => mb_substr($reason, 0, 1000)]);
                    $this->comment($user, $r, 'BLOCKER', "Kendala: {$reason}");
                    $this->log($r, $user, 'BLOCKED', ['alasan' => $reason]);
                    $notifyCreator = ['warning', "{$r->number} terkendala", "{$user->name}: {$reason}"];
                    if (! empty($data['proposed_deadline'])) {
                        $this->extension($user, $r, $data['proposed_deadline'], $reason);
                    }
                    break;
                case 'extension':
                    $need(['DITERIMA', 'IN_PROGRESS', 'REVISI', 'TERKENDALA'], 'Perpanjangan hanya untuk tugas yang dikerjakan.');
                    $this->extension($user, $r, $data['proposed_deadline'] ?? null, trim((string) ($data['reason'] ?? '')));
                    break;
                case 'submit':
                    $need(['DITERIMA', 'IN_PROGRESS', 'REVISI'], 'Hasil hanya bisa dikirim saat tugas dikerjakan.');
                    $this->submit($user, $r, $a, $data, $notifyCreator);
                    break;
                default:
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

    private function extension(User $user, ImportantRequest $r, mixed $proposed, string $reason): void
    {
        if (! $proposed || $reason === '') {
            throw ValidationException::withMessages(['proposed_deadline' => 'Isi tanggal usulan dan alasannya.']);
        }
        $date = Carbon::parse($proposed);
        $this->comment($user, $r, 'BLOCKER', 'Mengajukan perpanjangan deadline ke '.$date->format('d/m/Y H:i').": {$reason}");
        $this->log($r, $user, 'EXTENSION_REQUESTED', ['usulan' => $date->toIso8601String(), 'alasan' => $reason]);
        $this->notify([$r->created_by], $r, 'warning', "Perpanjangan deadline {$r->number}", "{$user->name} mengusulkan ".$date->format('d/m/Y H:i').": {$reason}", $user->id);
    }

    private function submit(User $user, ImportantRequest $r, ImportantRequestAssignee $a, array $data, ?array &$notifyCreator): void
    {
        $note = trim((string) ($data['note'] ?? ''));
        $tokens = $data['attachments'] ?? [];
        if ($note === '' && ! $tokens) {
            throw ValidationException::withMessages(['attachments' => 'Lampirkan file hasil atau tulis catatan hasil pekerjaan.']);
        }
        $round = 1 + (int) ImportantRequestSubmission::where('important_request_id', $r->id)
            ->when($r->assignment_mode === 'INDIVIDUAL', fn ($q) => $q->where('assignee_id', $a->id))->count();
        $auto = ! $r->requires_approval;
        $sub = ImportantRequestSubmission::create([
            'important_request_id' => $r->id, 'assignee_id' => $a->id, 'user_id' => $user->id, 'round' => $round,
            'note' => $note ?: null, 'status' => $auto ? 'OTOMATIS' : 'MENUNGGU_REVIEW',
        ]);
        $files = $this->files->claim($user, $tokens, $r, $round > 1 ? 'REVISION' : 'SUBMISSION', submissionId: $sub->id);
        // collective: one result moves every recipient still working on it
        $targets = $r->assignment_mode === 'COLLECTIVE'
            ? ImportantRequestAssignee::where('important_request_id', $r->id)->where('status', '!=', 'SELESAI')->get() : collect([$a]);
        foreach ($targets as $t) {
            $t->update($auto
                ? ['status' => 'SELESAI', 'progress' => 100, 'submitted_at' => now(), 'completed_at' => now()]
                : ['status' => 'MENUNGGU_REVIEW', 'progress' => 100, 'submitted_at' => now()]);
        }
        $this->log($r, $user, 'SUBMITTED', ['putaran' => $round, 'files' => array_map(fn ($f) => $f->original_name, $files), 'otomatis_selesai' => $auto]);
        $notifyCreator = $auto
            ? ['success', "{$r->number} selesai", "{$user->name} mengirim hasil (tanpa persetujuan, otomatis selesai)."]
            : ['info', "Hasil {$r->number} menunggu review", "{$user->name} mengirim hasil".($round > 1 ? " revisi ke-".($round - 1) : '').'.'];
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
                ? ImportantRequestAssignee::where('important_request_id', $r->id)->where('status', 'MENUNGGU_REVIEW')->get()
                : ImportantRequestAssignee::whereKey($sub->assignee_id)->get();
            foreach ($targets as $t) {
                $t->update($approve ? ['status' => 'SELESAI', 'completed_at' => now()] : ['status' => 'REVISI', 'progress' => min($t->progress, 90)]);
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
            $request->update(['status' => 'MENUNGGU_KONFIRMASI', 'completed_at' => null, 'cancelled_at' => null, 'cancel_reason' => null]);
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

    /** The request status from its recipients. */
    public function recompute(ImportantRequest $r): void
    {
        if (in_array($r->status, ['DRAFT', 'DIBATALKAN'], true)) {
            return;
        }
        $s = $r->assignees()->pluck('status');
        $next = match (true) {
            $s->isEmpty() => 'MENUNGGU_KONFIRMASI',
            $s->every(fn ($x) => $x === 'SELESAI') => 'SELESAI',
            $s->contains('TERKENDALA') => 'TERKENDALA',
            $s->contains('MENUNGGU_REVIEW') => 'MENUNGGU_REVIEW',
            $s->contains('REVISI') => 'REVISI',
            $s->contains('IN_PROGRESS') => 'IN_PROGRESS',
            $s->contains('DITERIMA') || $s->contains('SELESAI') => $s->contains('MENUNGGU_KONFIRMASI') ? 'MENUNGGU_KONFIRMASI' : 'DITERIMA',
            default => 'MENUNGGU_KONFIRMASI',
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

    private function openAssigneeIds(ImportantRequest $r): array
    {
        return $r->assignees()->where('status', '!=', 'SELESAI')->pluck('user_id')->all();
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
