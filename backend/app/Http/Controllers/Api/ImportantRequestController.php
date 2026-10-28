<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportantRequest;
use App\Models\ImportantRequestAttachment;
use App\Models\ImportantRequestComment;
use App\Models\ImportantRequestSubmission;
use App\Models\User;
use App\Services\ImportantRequest\ImportantRequestFiles;
use App\Services\ImportantRequest\ImportantRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Important Request API — every action is checked here (who may see / do it), never only by the
 * buttons the page shows. Lists are paginated; files go through ImportantRequestFiles.
 */
class ImportantRequestController extends Controller
{
    public function __construct(private ImportantRequestService $service, private ImportantRequestFiles $files) {}

    /** GET /api/important-requests?scope=mine|tasks|review|done|all&status=&priority=&overdue=1&search=&sort=&page= */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->string('scope', 'all')->value();
        $q = ImportantRequest::query()->with('creator:id,name', 'assignees.user:id,name');
        match ($scope) {
            'mine' => $q->where('created_by', $user->id),
            'tasks' => $q->where('status', '!=', 'DRAFT')->whereHas('assignees', fn ($a) => $a->where('user_id', $user->id)),
            'review' => $q->where('created_by', $user->id)->whereHas('submissions', fn ($s) => $s->where('status', 'MENUNGGU_REVIEW')),
            'done' => $q->visibleTo($user)->where('status', 'SELESAI'),
            default => $q->visibleTo($user),
        };
        $request->whenFilled('status', fn ($v) => $q->whereIn('status', explode(',', (string) $v)));
        $request->whenFilled('priority', fn ($v) => $q->where('priority', $v));
        if ($request->boolean('overdue')) {
            $q->overdue();
        }
        foreach (preg_split('/\s+/', $request->string('search')->trim()->value(), -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $q->where(fn ($x) => $x->where('number', 'like', "%{$w}%")->orWhere('title', 'like', "%{$w}%"));
        }
        match ($request->string('sort', 'recent')->value()) {
            'deadline' => $q->orderByRaw('deadline IS NULL')->orderBy('deadline'),
            'priority' => $q->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END")->orderBy('deadline'),
            default => $q->orderByDesc('updated_at'),
        };
        $page = $q->paginate(min(100, max(5, (int) $request->integer('per_page', 20))));
        $unread = $this->unreadCounts($user, collect($page->items())->pluck('id'));

        return response()->json([
            'data' => collect($page->items())->map(fn (ImportantRequest $r) => $this->listRow($r, $user, $unread[$r->id] ?? 0)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /api/important-requests/summary — the dashboard and the menu badge (what needs my action). */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->service->remind(); // no scheduler on this server: reminders go out when anyone looks (each once)
        $visible = fn () => ImportantRequest::query()->visibleTo($user)->where('status', '!=', 'DRAFT');
        $count = fn ($status) => $visible()->whereIn('status', (array) $status)->count();
        $myTasks = DB::table('important_request_assignees as a')->join('important_requests as r', 'r.id', '=', 'a.important_request_id')
            ->where('a.user_id', $user->id)->whereNotIn('r.status', ImportantRequest::CLOSED);
        $toConfirm = (clone $myTasks)->where('a.status', 'MENUNGGU_KONFIRMASI')->count();
        $toRevise = (clone $myTasks)->where('a.status', 'REVISI')->count();
        $toReview = ImportantRequest::where('created_by', $user->id)->whereNotIn('status', ImportantRequest::CLOSED)
            ->whereHas('submissions', fn ($s) => $s->where('status', 'MENUNGGU_REVIEW'))->count();
        $list = fn ($q) => $q->with('creator:id,name', 'assignees.user:id,name')->limit(5)->get()->map(fn ($r) => $this->listRow($r, $user, 0));

        return response()->json(['data' => [
            'badge' => $toConfirm + $toRevise + $toReview,
            'action' => ['to_confirm' => $toConfirm, 'to_revise' => $toRevise, 'to_review' => $toReview, 'my_open_tasks' => (clone $myTasks)->count()],
            'stats' => [
                'total' => $visible()->count(),
                'unconfirmed' => $count('MENUNGGU_KONFIRMASI'),
                'in_progress' => $count(['DITERIMA', 'IN_PROGRESS', 'REVISI']),
                'blocked' => $count('TERKENDALA'),
                'overdue' => $visible()->overdue()->count(),
                'waiting_review' => $count('MENUNGGU_REVIEW'),
                'done' => $count('SELESAI'),
            ],
            'latest' => $list($visible()->orderByDesc('updated_at')),
            'urgent' => $list($visible()->whereNotIn('status', ImportantRequest::CLOSED)->whereIn('priority', ['URGENT', 'HIGH'])->orderByRaw("CASE priority WHEN 'URGENT' THEN 0 ELSE 1 END")->orderBy('deadline')),
            'near_deadline' => $list($visible()->whereNotIn('status', ImportantRequest::CLOSED)->whereNotNull('deadline')->where('deadline', '<=', now()->addDays(3))->orderBy('deadline')),
            'can_create' => $user->hasPermission('important_request.create'),
        ]]);
    }

    /** GET /api/important-requests/recipients?search= — people who may be given a request (with their divisi). */
    public function recipients(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = ImportantRequestService::assignableUsers()->where('users.id', '!=', $user->id)->orderBy('users.name');
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('users.name', 'like', "%{$s}%")->orWhere('users.username', 'like', "%{$s}%"));
        }
        $users = $q->limit(500)->get(['users.id', 'users.name', 'users.username', 'users.position']);
        $divisi = DB::table('employees')->join('departments', 'departments.id', '=', 'employees.department_id')
            ->whereIn('employees.user_id', $users->pluck('id'))->pluck('departments.name', 'employees.user_id');

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username,
            'position' => $u->position, 'division' => $divisi[$u->id] ?? null])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $made = $this->service->create($request->user(), $data);

        return $this->show($request, $made)->setStatusCode(201);
    }

    public function show(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $user = $request->user();
        $r = $importantRequest;
        abort_unless($this->service->canView($user, $r), 403, 'Request ini bukan untuk Anda.');
        $r->load(['creator:id,name', 'assignees.user:id,name,username,position', 'submissions.user:id,name', 'submissions.reviewer:id,name',
            'attachments' => fn ($q) => $q->whereNull('deleted_at')->with('uploader:id,name')->orderBy('id')]);
        $unread = $this->unreadCounts($user, collect([$r->id]))[$r->id] ?? 0;
        DB::table('important_request_reads')->updateOrInsert(['important_request_id' => $r->id, 'user_id' => $user->id], ['last_read_at' => now()]);
        $mine = $r->assignees->firstWhere('user_id', $user->id);
        $creator = $r->created_by === $user->id;
        $open = ! in_array($r->status, ['SELESAI', 'DIBATALKAN'], true);
        $files = $r->attachments->groupBy('submission_id');

        return response()->json(['data' => [
            ...$this->listRow($r, $user, $unread),
            'description' => $r->description, 'category' => $r->category, 'note' => $r->note,
            'requires_approval' => $r->requires_approval, 'assignment_mode' => $r->assignment_mode,
            'sent_at' => $r->sent_at, 'completed_at' => $r->completed_at, 'cancelled_at' => $r->cancelled_at, 'cancel_reason' => $r->cancel_reason,
            'assignees' => $r->assignees->map(fn ($a) => [
                'id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->user?->name, 'username' => $a->user?->username, 'position' => $a->user?->position,
                'status' => $a->status, 'status_label' => ImportantRequestService::STATUS_LABELS[$a->status] ?? $a->status, 'progress' => $a->progress,
                'blocker_note' => $a->blocker_note, 'confirmed_at' => $a->confirmed_at, 'started_at' => $a->started_at, 'submitted_at' => $a->submitted_at, 'completed_at' => $a->completed_at,
            ])->values(),
            'attachments' => $r->attachments->whereNull('comment_id')->whereNull('submission_id')->map(fn ($f) => $this->file($f))->values(),
            'submissions' => $r->submissions->sortByDesc('id')->map(fn ($s) => [
                'id' => $s->id, 'round' => $s->round, 'assignee_id' => $s->assignee_id, 'user' => $s->user?->name, 'note' => $s->note,
                'status' => $s->status, 'reviewed_by' => $s->reviewer?->name, 'reviewed_at' => $s->reviewed_at, 'review_note' => $s->review_note, 'created_at' => $s->created_at,
                'files' => ($files[$s->id] ?? collect())->map(fn ($f) => $this->file($f))->values(),
                'can_review' => $creator && $s->status === 'MENUNGGU_REVIEW' && $open && $s->user_id !== $user->id,
            ])->values(),
            'me' => [
                'is_creator' => $creator,
                'assignee' => $mine ? ['id' => $mine->id, 'status' => $mine->status, 'progress' => $mine->progress] : null,
                'can_edit' => $creator && $open,
                'can_send' => $creator && $r->status === 'DRAFT',
                'can_delete' => $creator && $r->status === 'DRAFT',
                'can_cancel' => $creator && $open && $r->status !== 'DRAFT',
                'can_reopen' => ! $open && ($creator || $user->hasPermission('important_request.view_all')),
                'can_comment' => $r->status !== 'DRAFT',
                'max_mb' => (int) config('stockwise.important_request_max_mb', 20),
            ],
        ]]);
    }

    public function update(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $data = $this->validated($request, true);
        $this->service->update($request->user(), $importantRequest, $data);
        if (! empty($data['attachments'])) {
            $this->service->addOriginals($request->user(), $importantRequest->refresh(), $data['attachments']);
        }

        return $this->show($request, $importantRequest->refresh());
    }

    public function destroy(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $this->service->deleteDraft($request->user(), $importantRequest);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function send(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $this->service->send($request->user(), $importantRequest);

        return $this->show($request, $importantRequest->refresh());
    }

    /** POST /api/important-requests/{id}/act {action: confirm|start|progress|blocker|submit|extension, ...} */
    public function act(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'start', 'progress', 'blocker', 'submit', 'extension'])],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'proposed_deadline' => ['nullable', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['string'],
        ], ['proposed_deadline.after' => 'Tanggal usulan harus setelah sekarang.']);
        $this->service->act($request->user(), $importantRequest, $data['action'], $data);

        return $this->show($request, $importantRequest->refresh());
    }

    public function review(Request $request, ImportantRequest $importantRequest, ImportantRequestSubmission $submission): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->service->review($request->user(), $importantRequest, $submission, $data['approve'], $data['note'] ?? null);

        return $this->show($request, $importantRequest->refresh());
    }

    public function deadline(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $data = $request->validate(['deadline' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Tulis alasan perubahan deadline.']);
        $this->service->changeDeadline($request->user(), $importantRequest, $data['deadline'], $data['reason']);

        return $this->show($request, $importantRequest->refresh());
    }

    public function cancel(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tulis alasan pembatalan.']);
        $this->service->cancel($request->user(), $importantRequest, $data['reason']);

        return $this->show($request, $importantRequest->refresh());
    }

    public function reopen(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Tulis alasan membuka kembali.']);
        $this->service->reopen($request->user(), $importantRequest, $data['reason']);

        return $this->show($request, $importantRequest->refresh());
    }

    // ------------------------------------------------------------------ discussion

    /** GET /{id}/comments?page= — newest threads first, replies under them, 20 threads per page. */
    public function comments(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->service->canView($user, $importantRequest), 403);
        $page = $importantRequest->comments()->whereNull('parent_id')->with([
            'user:id,name,username', 'attachments' => fn ($q) => $q->whereNull('deleted_at')->with('uploader:id,name'),
            'replies.user:id,name,username', 'replies.attachments' => fn ($q) => $q->whereNull('deleted_at')->with('uploader:id,name'),
        ])->orderByDesc('id')->paginate(20);
        $lastRead = DB::table('important_request_reads')->where(['important_request_id' => $importantRequest->id, 'user_id' => $user->id])->value('last_read_at');
        $row = fn (ImportantRequestComment $c) => [
            'id' => $c->id, 'parent_id' => $c->parent_id, 'kind' => $c->kind, 'user' => $c->user?->name, 'user_id' => $c->user_id,
            'body' => $c->deleted_at ? null : $c->body, 'deleted' => (bool) $c->deleted_at, 'edited' => (bool) $c->edited_at,
            'created_at' => $c->created_at, 'is_new' => $c->user_id !== $user->id && (! $lastRead || $c->created_at > $lastRead),
            'can_edit' => $c->user_id === $user->id && $c->kind === 'COMMENT' && ! $c->deleted_at,
            'can_delete' => ($c->user_id === $user->id || $user->hasPermission('important_request.view_all')) && $c->kind === 'COMMENT' && ! $c->deleted_at,
            'files' => $c->deleted_at ? [] : $c->attachments->map(fn ($f) => $this->file($f))->values(),
        ];

        return response()->json([
            'data' => collect($page->items())->map(fn ($c) => $row($c) + ['replies' => $c->replies->map($row)->values()]),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function addComment(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'], 'parent_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:10'], 'attachments.*' => ['string'],
        ]);
        $c = $this->service->addComment($request->user(), $importantRequest, (string) ($data['body'] ?? ''), $data['parent_id'] ?? null, $data['attachments'] ?? []);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    public function editComment(Request $request, ImportantRequest $importantRequest, ImportantRequestComment $comment): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $this->service->editComment($request->user(), $importantRequest, $comment, $data['body']);

        return response()->json(['data' => ['id' => $comment->id]]);
    }

    public function deleteComment(Request $request, ImportantRequest $importantRequest, ImportantRequestComment $comment): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $this->service->deleteComment($request->user(), $importantRequest, $comment);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** GET /{id}/activities — the audit trail, newest first. */
    public function activities(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $page = $importantRequest->activities()->with('user:id,name')->orderByDesc('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'user' => $a->user?->name ?? 'Sistem', 'meta' => $a->meta, 'created_at' => $a->created_at]),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    // ------------------------------------------------------------------ files

    /** POST /api/important-request-uploads {name, size} → token; then the chunks */
    public function uploadStart(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => ['token' => $this->files->start($request->user(), $data['name'], $data['size']),
            'chunk' => ImportantRequestFiles::CHUNK_MAX]], 201);
    }

    /** POST /api/important-request-uploads/{token}?offset= (raw bytes) */
    public function uploadChunk(Request $request, string $token): JsonResponse
    {
        return response()->json(['data' => $this->files->chunk($request->user(), $token, (int) $request->query('offset', 0), $request->getContent())]);
    }

    /** POST /{id}/attachments {attachments: [token]} — the creator adds original files */
    public function addAttachments(Request $request, ImportantRequest $importantRequest): JsonResponse
    {
        $data = $request->validate(['attachments' => ['required', 'array', 'min:1', 'max:10'], 'attachments.*' => ['string']]);
        $this->service->addOriginals($request->user(), $importantRequest, $data['attachments']);

        return $this->show($request, $importantRequest->refresh());
    }

    public function deleteAttachment(Request $request, ImportantRequest $importantRequest, ImportantRequestAttachment $attachment): JsonResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        $this->service->deleteAttachment($request->user(), $importantRequest, $attachment);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** GET /{id}/attachments/{file}?inline=1 — only for who may see the request; a preview only for pdf / images. */
    public function download(Request $request, ImportantRequest $importantRequest, ImportantRequestAttachment $attachment): StreamedResponse
    {
        abort_unless($this->service->canView($request->user(), $importantRequest), 403);
        abort_unless($attachment->important_request_id === $importantRequest->id && ! $attachment->deleted_at, 404);
        abort_unless(Storage::disk(ImportantRequestFiles::DISK)->exists($attachment->path), 404);
        $inline = $request->boolean('inline') && in_array($attachment->extension, ImportantRequestFiles::PREVIEW, true);

        return Storage::disk(ImportantRequestFiles::DISK)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    // ------------------------------------------------------------------ analytics

    /** GET /api/important-requests/analytics?months=6 — for Tracking & Analytic (permission important_request.analytics). */
    public function analytics(Request $request): JsonResponse
    {
        $months = max(1, min(24, (int) $request->integer('months', 6)));
        $from = now()->startOfMonth()->subMonths($months - 1);
        $sent = ImportantRequest::where('status', '!=', 'DRAFT')->where('sent_at', '>=', $from);
        $total = (clone $sent)->where('status', '!=', 'DIBATALKAN')->count();
        $done = (clone $sent)->where('status', 'SELESAI')->get(['sent_at', 'completed_at', 'deadline']);
        $hours = $done->filter(fn ($r) => $r->completed_at)->map(fn ($r) => $r->sent_at->diffInMinutes($r->completed_at) / 60);
        $lateDone = $done->filter(fn ($r) => $r->deadline && $r->completed_at && $r->completed_at > $r->deadline)->count();
        $revised = DB::table('important_request_submissions')->where('status', 'REVISI')->where('created_at', '>=', $from)->distinct()->count('important_request_id');
        $byDivisi = DB::table('important_request_assignees as a')->join('important_requests as r', 'r.id', '=', 'a.important_request_id')
            ->leftJoin('employees as e', 'e.user_id', '=', 'a.user_id')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('r.status', '!=', 'DRAFT')->where('r.sent_at', '>=', $from)
            ->groupBy(DB::raw("COALESCE(d.name, 'Tanpa divisi')"))->selectRaw("COALESCE(d.name, 'Tanpa divisi') as divisi, COUNT(DISTINCT r.id) as n")
            ->orderByDesc('n')->get();
        $workload = DB::table('important_request_assignees as a')->join('important_requests as r', 'r.id', '=', 'a.important_request_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')->whereNotIn('r.status', ImportantRequest::CLOSED)->where('a.status', '!=', 'SELESAI')
            ->groupBy('u.id', 'u.name')->selectRaw('u.name, COUNT(*) as open, SUM(CASE WHEN r.deadline < ? THEN 1 ELSE 0 END) as overdue', [now()])
            ->orderByDesc('open')->limit(15)->get();
        $trend = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $trend[] = ['month' => $m->format('Y-m'),
                'dibuat' => ImportantRequest::where('status', '!=', 'DRAFT')->whereBetween('sent_at', [$m, $m->copy()->endOfMonth()])->count(),
                'selesai' => ImportantRequest::where('status', 'SELESAI')->whereBetween('completed_at', [$m, $m->copy()->endOfMonth()])->count()];
        }

        return response()->json(['data' => [
            'months' => $months,
            'definitions' => [
                'completion_rate' => 'Request selesai ÷ request terkirim (tidak termasuk dibatalkan) pada periode ini.',
                'avg_hours' => 'Rata-rata jam dari dikirim sampai selesai.',
                'overdue_open' => 'Request belum selesai yang sudah melewati deadline (sekarang).',
                'late_done' => 'Request selesai yang selesainya setelah deadline.',
                'revised' => 'Request yang pernah diminta revisi.',
            ],
            'total' => $total,
            'done' => $done->count(),
            'completion_rate' => $total ? round($done->count() / $total * 100, 1) : null,
            'avg_hours' => $hours->isNotEmpty() ? round($hours->avg(), 1) : null,
            'overdue_open' => ImportantRequest::overdue()->count(),
            'late_done' => $lateDone,
            'revised' => $revised,
            'by_divisi' => $byDivisi,
            'workload' => $workload,
            'trend' => $trend,
        ]]);
    }

    // ------------------------------------------------------------------ helpers

    private function validated(Request $request, bool $update = false): array
    {
        $s = $update ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$s, 'string', 'max:200'],
            'description' => [$s, 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::in(ImportantRequest::PRIORITIES)],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'deadline' => ['sometimes', 'nullable', 'date', $update ? 'nullable' : 'after:now'],
            'requires_approval' => ['sometimes', 'boolean'],
            'assignment_mode' => ['sometimes', Rule::in(ImportantRequest::MODES)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assignee_ids' => ['sometimes', 'array', 'max:50'],
            'assignee_ids.*' => ['integer'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['string'],
            'send' => ['sometimes', 'boolean'],
        ], [
            'title.required' => 'Judul wajib diisi.', 'description.required' => 'Deskripsi / instruksi wajib diisi.',
            'deadline.after' => 'Deadline harus setelah sekarang.',
        ]);
    }

    private function listRow(ImportantRequest $r, User $user, int $unread): array
    {
        $a = $r->assignees;
        $mine = $a->firstWhere('user_id', $user->id);

        return [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'priority' => $r->priority,
            'status' => $r->status, 'status_label' => ImportantRequestService::STATUS_LABELS[$r->status] ?? $r->status,
            'deadline' => $r->deadline, 'overdue' => $r->isOverdue(),
            'creator' => $r->creator?->name, 'created_by' => $r->created_by, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
            'assignee_names' => $a->map(fn ($x) => $x->user?->name)->filter()->values(),
            'assignees_done' => $a->where('status', 'SELESAI')->count(), 'assignees_total' => $a->count(),
            'my_status' => $mine?->status, 'my_status_label' => $mine ? (ImportantRequestService::STATUS_LABELS[$mine->status] ?? $mine->status) : null,
            'unread_comments' => $unread,
        ];
    }

    private function file(ImportantRequestAttachment $f): array
    {
        return ['id' => $f->id, 'kind' => $f->kind, 'name' => $f->original_name, 'size' => $f->size, 'mime' => $f->mime, 'extension' => $f->extension,
            'uploader' => $f->uploader?->name, 'uploaded_by' => $f->uploaded_by, 'created_at' => $f->created_at,
            'previewable' => in_array($f->extension, ImportantRequestFiles::PREVIEW, true)];
    }

    /** request id => comments by others since this user last opened it */
    private function unreadCounts(User $user, $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('important_request_comments as c')
            ->leftJoin('important_request_reads as rd', fn ($j) => $j->on('rd.important_request_id', '=', 'c.important_request_id')->where('rd.user_id', $user->id))
            ->whereIn('c.important_request_id', $ids)->where('c.user_id', '!=', $user->id)->whereNull('c.deleted_at')
            ->where(fn ($w) => $w->whereNull('rd.last_read_at')->orWhereColumn('c.created_at', '>', 'rd.last_read_at'))
            ->groupBy('c.important_request_id')->selectRaw('c.important_request_id as id, COUNT(*) as n')->pluck('n', 'id')->map(fn ($n) => (int) $n)->all();
    }
}
