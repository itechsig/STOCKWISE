<?php

namespace App\Services;

use App\Models\ItemRequest;
use App\Models\ItemRequestApproval;
use App\Models\PpbDraft;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approvals of a Request Barang whose lines admin gudang proposed to give from stock (NPBG):
 *
 *   APPROVAL_MANAGER  → Manager approves (item_request.approve_manager)
 *   APPROVAL_DIREKTUR → Direktur approves (item_request.approve_director)
 *   then Gudang fulfils it from real stock — ItemRequestFulfillmentService
 *
 * Each approver may change the approved quantity of a line (lower or higher than asked), send the request back
 * to admin gudang (PERLU_PERBAIKAN), or reject it (DITOLAK). The approved quantity is the official
 * need; once approved the system gives what the stock has (NPBG, maybe partial) and puts the rest
 * in a Draft PPB (ItemRequestFulfillmentService::allocate). The Direktur may act while it still waits
 * for the Manager (bypass: the Manager step is logged as approved automatically); whoever holds both
 * rights (e.g. super_admin) still goes step by step. Every step is logged in item_request_approvals.
 */
class ItemRequestApprovalService
{
    /** Legacy (one serah terima per request, before fulfilments): its photos. */
    public const PHOTO_ENTITY = 'item_request';

    /** status => [log stage, permission, who, next status] */
    public const STAGES = [
        'APPROVAL_MANAGER' => ['MANAGER', 'item_request.approve_manager', 'Manager', 'APPROVAL_DIREKTUR'],
        'APPROVAL_DIREKTUR' => ['DIREKTUR', 'item_request.approve_director', 'Direktur', 'DISIAPKAN'],
    ];

    public const HANDOVER_PERMISSION = 'item_request.handover';

    /** Every permission that sees the Approval page. */
    public const PERMISSIONS = ['item_request.approve_manager', 'item_request.approve_director', 'item_request.handover'];

    public const STATUS_LABELS = [
        'MENUNGGU' => 'Menunggu admin',
        'DIPROSES' => 'Diproses admin gudang',
        'PERLU_PERBAIKAN' => 'Perlu perbaikan',
        'APPROVAL_MANAGER' => 'Persetujuan Manajer',
        'APPROVAL_DIREKTUR' => 'Persetujuan Direktur',
        'DISIAPKAN' => 'Siapkan & serah terima gudang',
        'SIAP_DISERAHKAN' => 'Siap serah terima',
        'DIPENUHI_SEBAGIAN' => 'Dipenuhi sebagian',
        'MENUNGGU_PENGADAAN' => 'Menunggu pengadaan',
        'MENUNGGU_BARANG' => 'Menunggu barang',
        'SELESAI' => 'Selesai',
        'DITOLAK' => 'Ditolak',
        'DIBATALKAN' => 'Dibatalkan',
        // a line whose rest was closed officially
        'DITUTUP' => 'Ditutup',
    ];

    /** The statuses this user acts on: the approval steps they hold, and Gudang's preparing / serah terima. */
    public static function actionableStatuses(User $user): array
    {
        $out = [];
        foreach (self::STAGES as $status => [, $perm]) {
            if ($user->hasPermission($perm)) {
                $out[] = $status;
            }
        }
        // the Direktur also sees what still waits for the Manager (bypass)
        if (self::bypassesManager($user) && ! in_array('APPROVAL_MANAGER', $out, true)) {
            array_unshift($out, 'APPROVAL_MANAGER');
        }
        if ($user->hasPermission(self::HANDOVER_PERMISSION)) {
            array_push($out, 'DISIAPKAN', 'SIAP_DISERAHKAN');
        }

        return $out;
    }

    /** A Direktur without the Manager right approves over the Manager's head. */
    public static function bypassesManager(User $user): bool
    {
        return $user->hasPermission('item_request.approve_director') && ! $user->hasPermission('item_request.approve_manager');
    }

    /** Who this user approves as on the request's current step: MANAGER / DIREKTUR, or null. */
    public static function actsAs(User $user, ItemRequest $request): ?string
    {
        if ($request->status === 'APPROVAL_MANAGER' && self::bypassesManager($user)) {
            return 'DIREKTUR';
        }
        $stage = self::STAGES[$request->status] ?? null;

        return $stage && $user->hasPermission($stage[1]) ? $stage[0] : null;
    }

    public static function canAct(User $user, ItemRequest $request): bool
    {
        return in_array($request->status, self::actionableStatuses($user), true);
    }

    public function log(ItemRequest $request, string $stage, string $action, ?User $user, ?string $note = null): void
    {
        ItemRequestApproval::create([
            'item_request_id' => $request->id, 'stage' => $stage, 'action' => $action,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'user_id' => $user?->id, 'user_name' => $user?->name ?? 'Sistem', 'created_at' => now(),
        ]);
    }

    /** Admin gudang finished validating: a request with NPBG lines goes to the Manager. */
    public function submitted(ItemRequest $request, User $admin): void
    {
        $this->log($request, 'ADMIN', 'VALIDASI', $admin);
        $this->notifyStage($request, $admin->id, false); // the requester already hears "diproses"
    }

    /**
     * APPROVE (optionally changing line quantities) moves the request one step on; RETURN sends it
     * back to admin gudang to fix (with a reason); REJECT (with a reason) ends it.
     *
     * @param  array<int, float>  $quantities  line id => approved quantity (any amount above 0)
     */
    public function decide(ItemRequest $request, User $user, string $action, ?string $note, array $quantities = []): ItemRequest
    {
        $approvalNote = null;
        $bypassed = false;
        DB::transaction(function () use (&$request, $user, $action, $note, $quantities, &$approvalNote, &$bypassed) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            $stage = self::STAGES[$request->status] ?? null;
            if (! $stage) {
                throw ValidationException::withMessages(['status' => 'Request ini sedang tidak menunggu approval ('.self::label($request->status).').']);
            }
            [$logStage, $perm, $who, $next] = $stage;
            // Direktur at the Manager step: acts as Direktur, the Manager step is approved for them
            $bypass = $request->status === 'APPROVAL_MANAGER' && self::bypassesManager($user);
            $bypassed = $bypass && $action === 'APPROVE';
            if ($bypass) {
                [$logStage, , , $next] = self::STAGES['APPROVAL_DIREKTUR'];
            } elseif (! $user->hasPermission($perm)) {
                throw ValidationException::withMessages(['status' => "Tahap ini menunggu {$who}. Anda tidak punya hak approval {$who}."]);
            }
            if (in_array($action, ['REJECT', 'RETURN'], true) && trim((string) $note) === '') {
                throw ValidationException::withMessages(['note' => $action === 'REJECT' ? 'Tulis alasan penolakan.' : 'Tulis apa yang perlu diperbaiki.']);
            }

            if ($action === 'REJECT') {
                $this->log($request, $logStage, 'REJECT', $user, $note);
                // nothing was issued yet (NPBG comes with Gudang's fulfilment); its open drafts go too
                PpbDraft::where('item_request_id', $request->id)->where('status', 'DRAFT')->update(['status' => 'DIBATALKAN']);
                $request->update(['status' => 'DITOLAK']);
            } elseif ($action === 'RETURN') {
                $this->log($request, $logStage, 'RETURN', $user, $note);
                // the proposed (NPBG) lines are decided again; the PPB ones already follow purchasing
                $request->lines()->where('decision', 'NPBG')->update(['decision' => null, 'approved_qty' => null, 'decided_by' => null, 'decided_at' => null]);
                $request->update(['status' => 'PERLU_PERBAIKAN']);
            } else {
                $changed = [];
                foreach ($request->lines()->where('decision', 'NPBG')->get() as $l) {
                    if (! array_key_exists($l->id, $quantities)) {
                        continue;
                    }
                    $q = round((float) $quantities[$l->id], 2);
                    if ($q <= 0) {
                        throw ValidationException::withMessages(["quantities.{$l->id}" => "Jumlah disetujui {$l->deskripsi_barang} harus lebih dari 0."]);
                    }
                    if (abs($q - (float) $l->approved_qty) > 0.0001) {
                        $changed[] = "{$l->deskripsi_barang} {$this->num($l->approved_qty)}→{$this->num($q)}";
                        $l->update(['approved_qty' => $q]);
                    }
                }
                $logNote = trim(($changed ? 'Jumlah disetujui diubah: '.implode(', ', $changed).'. ' : '').(string) $note);
                $approvalNote = $logNote !== '' ? $logNote : null;
                if ($bypass) {
                    $this->log($request, 'MANAGER', 'APPROVE', $user, "Disetujui otomatis: Direktur {$user->name} langsung approve (bypass)");
                }
                $this->log($request, $logStage, 'APPROVE', $user, $logNote);
                $request->update(['status' => $next]);
                if ($next === 'DISIAPKAN') {
                    // approved: the system gives from the real stock (NPBG, maybe partial) and buys the rest (Draft PPB)
                    $request = app(ItemRequestFulfillmentService::class)->allocate($request)->refresh();
                }
            }
        });

        if ($request->status === 'DITOLAK') {
            NotificationDispatcher::toUser($request->requester_id, 'request', 'warning', "Request {$request->number} ditolak",
                'Alasan: '.trim((string) $note), "/item-requests/{$request->id}");
            NotificationDispatcher::toPermission('item_request.process', 'request', 'warning', "Request {$request->number} ditolak",
                "{$user->name}: ".trim((string) $note), "/item-requests/{$request->id}", $user->id);
        } elseif ($request->status === 'PERLU_PERBAIKAN') {
            NotificationDispatcher::toPermission('item_request.process', 'request', 'warning', "Request {$request->number} perlu perbaikan",
                "{$user->name}: ".trim((string) $note), "/item-requests/{$request->id}", $user->id);
            NotificationDispatcher::toUser($request->requester_id, 'request', 'info', "Request {$request->number}: perlu perbaikan",
                'Admin gudang sedang memperbaiki permintaan Anda.', "/item-requests/{$request->id}");
        } else {
            $this->notifyStage($request, $user->id);
            if ($bypassed) {
                // the Manager knows their step was approved for them
                NotificationDispatcher::toPermission('item_request.approve_manager', 'request', 'info', "{$request->number} disetujui langsung oleh Direktur",
                    "Tahap Manajer disetujui otomatis (bypass oleh {$user->name}).", "/item-requests/{$request->id}", $user->id);
            }
            // a note / changed quantities from the approver: admin gudang and the requester know it too
            if ($approvalNote) {
                $who = $user->hasPermission('item_request.approve_director') && ! $user->hasPermission('item_request.approve_manager') ? 'Direktur' : 'Manager';
                NotificationDispatcher::toPermission('item_request.process', 'request', 'info', "Catatan {$who} untuk {$request->number}",
                    "{$user->name}: {$approvalNote}", "/item-requests/{$request->id}", $user->id);
                NotificationDispatcher::toUser($request->requester_id, 'request', 'info', "Catatan {$who} untuk request {$request->number}",
                    "{$user->name}: {$approvalNote}", "/item-requests/{$request->id}");
            }
        }

        return $request;
    }

    /** Tell whoever acts next (and the requester) that the request moved on. */
    private function notifyStage(ItemRequest $request, ?int $actorId, bool $tellRequester = true): void
    {
        $link = "/approvals?focus={$request->id}";
        $what = "{$request->number} dari {$request->requester_name}";
        match ($request->status) {
            'APPROVAL_MANAGER' => [
                NotificationDispatcher::toPermission('item_request.approve_manager', 'request', 'info', 'Persetujuan Manajer dibutuhkan', "{$what} menunggu persetujuan Anda.", $link, $actorId),
                // the Direktur sees it at once too and may approve it straight away (bypass)
                NotificationDispatcher::toPermission('item_request.approve_director', 'request', 'info', 'Request masuk (menunggu Manajer)', "{$what} menunggu Manajer. Anda bisa langsung approve (bypass).", $link, $actorId),
            ],
            'APPROVAL_DIREKTUR' => NotificationDispatcher::toPermission('item_request.approve_director', 'request', 'info', 'Persetujuan Direktur dibutuhkan', "{$what} sudah disetujui Manajer, menunggu persetujuan Anda.", $link, $actorId),
            'DISIAPKAN' => NotificationDispatcher::toPermission(self::HANDOVER_PERMISSION, 'request', 'info', 'Siapkan barang', "{$what} sudah disetujui. Tentukan jumlah yang diserahkan dari stok.", "/item-requests/{$request->id}", $actorId),
            'SIAP_DISERAHKAN' => NotificationDispatcher::toPermission(self::HANDOVER_PERMISSION, 'request', 'info', 'Barang siap diserahkan', "{$what} sudah disetujui, NPBG dibuat dari stok. Lakukan serah terima.", "/item-requests/{$request->id}", $actorId),
            default => null,
        };
        if ($tellRequester) {
            NotificationDispatcher::toUser($request->requester_id, 'request', 'info', "Request {$request->number}: ".self::label($request->status),
                'Status permintaan Anda diperbarui.', "/item-requests/{$request->id}");
        }
    }

    public static function label(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    private function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    }

    /**
     * The steps of a request for the status tracker (app + Cek Permintaan), each
     * done / current / rejected / cancelled / todo / skipped, with when and by whom.
     *
     * @return list<array{key:string,label:string,state:string,at:?string,by:?string,note:?string}>
     */
    public static function timeline(ItemRequest $request): array
    {
        $request->loadMissing(['lines', 'approvals', 'processor:id,name']);
        $logs = $request->approvals;
        $lines = $request->lines;
        $iso = fn ($t) => $t?->copy()->timezone(config('app.timezone'))->toIso8601String();
        $last = fn (string $stage, ?string $action = null) => $logs->where('stage', $stage)
            ->when($action, fn ($c) => $c->where('action', $action))->sortByDesc('id')->first();
        // not decided yet: the full flow is shown (most requests are given from stock)
        $hasNpbg = in_array($request->status, ['MENUNGGU', 'DIPROSES'], true) || $lines->contains(fn ($l) => $l->decision === 'NPBG')
            || $logs->contains(fn ($a) => in_array($a->stage, ['MANAGER', 'DIREKTUR'], true));
        // finished before the fulfilment flow existed (no log of its own)
        $legacy = $request->status === 'SELESAI' && $logs->isEmpty();
        $approved = (float) $lines->sum(fn ($l) => in_array($l->decision, ['NPBG', 'PPB'], true) ? (float) $l->approved_qty : 0);
        $given = (float) $lines->sum('fulfilled_qty');
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');

        $steps = [['key' => 'DIKIRIM', 'label' => 'Request dikirim', 'at' => $iso($request->created_at), 'by' => $request->requester_name, 'note' => null]];
        $valid = $last('ADMIN');
        $steps[] = ['key' => 'VALIDASI', 'label' => 'Divalidasi admin gudang', 'at' => $iso($valid?->created_at ?? (in_array($request->status, ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'], true) ? null : $request->processed_at)),
            'by' => $valid?->user_name ?? $request->processor?->name, 'note' => $request->status === 'PERLU_PERBAIKAN' ? $last('MANAGER', 'RETURN')?->note ?? $last('DIREKTUR', 'RETURN')?->note : null];

        // Gudang prepares and hands over in one step
        $handLabel = 'Siapkan & serah terima gudang'.($given > 0 && $given + 0.0001 < $approved ? " (sebagian {$num($given)} dari {$num($approved)})" : '');
        if ($legacy) {
            $onlyPpb = $lines->isNotEmpty() && $lines->every(fn ($l) => in_array($l->decision, ['PPB', 'TOLAK'], true)) && $lines->contains(fn ($l) => $l->decision === 'PPB');
            $steps[] = ['key' => 'SELESAI', 'label' => $onlyPpb ? 'Diteruskan ke pembelian (PPB)' : 'Selesai', 'at' => $iso($request->processed_at), 'by' => null, 'note' => null];
        } elseif ($hasNpbg) {
            foreach (['MANAGER' => 'Persetujuan Manajer', 'DIREKTUR' => 'Persetujuan Direktur'] as $stage => $label) {
                $log = $last($stage);
                $auto = $stage === 'MANAGER' && $log?->action === 'APPROVE' && str_starts_with((string) $log->note, 'Disetujui otomatis');
                $steps[] = ['key' => $stage, 'label' => $auto ? 'Persetujuan Manajer (otomatis, bypass Direktur)' : $label, 'at' => $iso($log?->created_at),
                    'by' => $auto ? 'bypass Direktur' : $log?->user_name, 'note' => $auto ? null : $log?->note];
            }
            $h = $last('SERAH_TERIMA');
            $steps[] = ['key' => 'SERAH_TERIMA', 'label' => $handLabel, 'at' => $iso($h?->created_at), 'by' => $h?->user_name, 'note' => null];
        } else {
            // only goods to buy: purchasing first, then Gudang hands them over when they arrive
            $s = $last('GUDANG', 'SHORTAGE');
            $steps[] = ['key' => 'PENGADAAN', 'label' => 'Pengadaan (PPB)', 'at' => $iso($s?->created_at ?? $request->processed_at), 'by' => null, 'note' => null];
            $h = $last('SERAH_TERIMA');
            $steps[] = ['key' => 'SERAH_TERIMA', 'label' => $handLabel, 'at' => $iso($h?->created_at), 'by' => $h?->user_name, 'note' => null];
        }

        $keys = array_column($steps, 'key');
        $current = match ($request->status) {
            'MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN' => 'VALIDASI',
            'APPROVAL_MANAGER' => 'MANAGER',
            'APPROVAL_DIREKTUR' => 'DIREKTUR',
            // Gudang prepares and hands over: one step
            'DISIAPKAN' => 'SERAH_TERIMA',
            'MENUNGGU_PENGADAAN' => in_array('PENGADAAN', $keys, true) && $given <= 0 ? 'PENGADAAN' : 'SERAH_TERIMA',
            'SIAP_DISERAHKAN', 'DIPENUHI_SEBAGIAN', 'MENUNGGU_BARANG' => 'SERAH_TERIMA',
            'DITOLAK' => ($r = $logs->where('action', 'REJECT')->sortByDesc('id')->first()) ? $r->stage : 'VALIDASI',
            default => null,
        };
        $at = $current !== null ? array_search($current, $keys, true) : false;
        foreach ($steps as $i => &$s) {
            if ($request->status === 'SELESAI' || $at === false) {
                $s['state'] = $request->status === 'DIBATALKAN' && $i > 0 ? ($i === 1 ? 'cancelled' : 'skipped') : 'done';
            } elseif ($i < $at) {
                $s['state'] = 'done';
            } elseif ($i === $at) {
                $s['state'] = $request->status === 'DITOLAK' ? 'rejected' : 'current';
            } else {
                $s['state'] = $request->status === 'DITOLAK' ? 'skipped' : 'todo';
            }
            if ($s['state'] === 'rejected') {
                $r = $logs->where('action', 'REJECT')->sortByDesc('id')->first();
                [$s['at'], $s['by'], $s['note']] = [$iso($r?->created_at ?? $request->processed_at), $r?->user_name ?? $request->processor?->name, $r?->note];
            }
        }

        return $steps;
    }
}
