<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PPB approval before PO. One click: APPROVE puts the PPB straight into the PO
 * table as a "Menunggu PO" entry (number PO/PPB/.., no vendor / price yet);
 * REJECT (with a reason) keeps it out — and takes that entry away again. The
 * real PO is still made in Accurate: once it is synced, the "Menunggu PO"
 * lines it covers go by themselves (no PO twice). When Accurate makes a PO for
 * a PPB nobody approved, the sync logs PO_TANPA_APPROVAL and tells the
 * approvers (scan()).
 *
 * Status of a PPB = its latest APPROVE / REJECT log row; none = MENUNGGU.
 * Only PPBs dated from GO_LIVE need approval — older ones were bought before
 * the rule existed (status null).
 *
 * A STOCKWISE PPB (made here, not in Accurate) is approved in two steps:
 * Manager (ppb.approve_manager, log APPROVE_MANAGER → MENUNGGU_DIREKTUR) then
 * Direktur (ppb.approve_director, log APPROVE → DISETUJUI). A Direktur without
 * the Manager right may approve while it still waits for the Manager (the Manager
 * step is logged as approved for them). Accurate's PPBs keep the one-step ppb.approve.
 * Its "Menunggu PO" entry is completed in STOCKWISE (vendor, price) into a STOCKWISE PO.
 */
class PpbApprovalService
{
    public const GO_LIVE = '2026-10-20';

    public const TABLE = 'ppb_approvals';

    /** status of a PO table entry made by an approval, still waiting for Accurate's real PO */
    public const WAITING = 'MENUNGGU_PO';

    /** the log actions that decide a PPB's status (the latest one wins) */
    /** RETURN: sent back to admin for a revision (note required); DIAJUKAN_ULANG: revised, to the Manager again */
    public const DECISIONS = ['APPROVE', 'REJECT', 'APPROVE_MANAGER', 'RETURN', 'DIAJUKAN_ULANG'];

    /** no_ppb of the given ones that were made in STOCKWISE (no Accurate REQID, made by a user) */
    public static function stockwiseNos(Collection $noPpbs): Collection
    {
        $nos = $noPpbs->filter()->unique()->values();

        return $nos->isEmpty() ? collect() : Ppb::query()->whereIn('no_ppb', $nos)->whereNull('accurate_reqid')->whereNotNull('created_by')
            ->distinct()->pluck('no_ppb');
    }

    public static function isStockwise(string $noPpb): bool
    {
        return self::stockwiseNos(collect([$noPpb]))->isNotEmpty();
    }

    public function __construct(private DocumentNumberService $numbers) {}

    /** @return array<string, array{status:string,at:?string,by:?string,note:?string,po_without_approval:bool}> no_ppb => status */
    public static function statusFor(Collection $noPpbs): array
    {
        $nos = $noPpbs->filter()->unique()->values();
        if ($nos->isEmpty()) {
            return [];
        }
        $log = DB::table(self::TABLE)->whereIn('no_ppb', $nos)->orderBy('id')->get()->groupBy('no_ppb');
        $dates = Ppb::query()->whereIn('no_ppb', $nos)->selectRaw('no_ppb, MIN(tgl_ppb) as tgl')->groupBy('no_ppb')->pluck('tgl', 'no_ppb');
        $stockwise = self::stockwiseNos($nos)->flip();

        return $nos->mapWithKeys(function ($no) use ($log, $dates, $stockwise) {
            $rows = $log->get($no, collect());
            $decision = $rows->whereIn('action', self::DECISIONS)->last();
            $sw = $stockwise->has($no);
            $required = $sw || (($dates[$no] ?? null) && substr((string) $dates[$no], 0, 10) >= self::GO_LIVE);
            $status = match ($decision?->action) {
                'APPROVE' => 'DISETUJUI',
                'REJECT' => 'DITOLAK',
                'APPROVE_MANAGER' => 'MENUNGGU_DIREKTUR',
                'RETURN' => 'DIKEMBALIKAN',
                default => $required ? 'MENUNGGU' : 'TIDAK_PERLU',
            };

            return [$no => [
                'status' => $status,
                'at' => self::iso($decision?->created_at),
                'by' => $decision?->user_name,
                'note' => $decision?->note,
                'po_without_approval' => $rows->contains('action', 'PO_TANPA_APPROVAL') && $decision?->action !== 'APPROVE',
                // STOCKWISE PPB: two steps; whose step it is now
                'stockwise' => $sw,
                'step' => ! $sw ? null : match ($status) {
                    'MENUNGGU', 'DITOLAK' => 'MANAGER',
                    'MENUNGGU_DIREKTUR' => 'DIREKTUR',
                    'DIKEMBALIKAN' => 'ADMIN',
                    default => null,
                },
            ]];
        })->all();
    }

    /** @return Collection<int, object> the log of one PPB, oldest first */
    public static function log(string $noPpb): Collection
    {
        return DB::table(self::TABLE)->where('no_ppb', $noPpb)->orderBy('id')->get(['id', 'action', 'note', 'user_name', 'created_at'])
            ->map(fn ($r) => tap($r, fn ($r) => $r->created_at = self::iso($r->created_at)));
    }

    /** A raw DB timestamp (app timezone) as ISO-8601 with its offset, so the browser shows the right local time. */
    private static function iso(?string $v): ?string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v, config('app.timezone'))->toIso8601String() : null;
    }

    /** Can this user act on this PPB now (its step, or Accurate's one-step approval)? */
    public static function canDecide(User $user, array $status): bool
    {
        if (! ($status['stockwise'] ?? false)) {
            return $user->hasPermission('ppb.approve') && $status['status'] !== 'TIDAK_PERLU';
        }

        return match ($status['status']) {
            'MENUNGGU', 'DITOLAK' => $user->hasPermission('ppb.approve_manager') || $user->hasPermission('ppb.approve_director'),
            'MENUNGGU_DIREKTUR', 'DISETUJUI' => $user->hasPermission('ppb.approve_director'),
            default => false,
        };
    }

    /** @param  array<int, float>  $quantities  STOCKWISE PPB only: ppb row id => the approved qty (a note is required when any changed) */
    public function decide(string $noPpb, User $user, string $action, ?string $note, array $quantities = []): void
    {
        if (! Ppb::where('no_ppb', $noPpb)->exists()) {
            throw ValidationException::withMessages(['no_ppb' => 'PPB tidak ditemukan.']);
        }
        if ($action === 'REJECT' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Alasan tolak wajib diisi.']);
        }
        if (self::isStockwise($noPpb)) {
            $this->decideStockwise($noPpb, $user, $action, $note, $quantities);

            return;
        }
        if (! $user->hasPermission('ppb.approve')) {
            throw ValidationException::withMessages(['status' => 'PPB dari Accurate di-approve oleh pemegang hak "Approve PPB".']);
        }
        DB::transaction(function () use ($noPpb, $user, $action, $note) {
            DB::table(self::TABLE)->where('no_ppb', $noPpb)->lockForUpdate()->get(['id']);
            $current = self::statusFor(collect([$noPpb]))[$noPpb]['status'];
            if (($current === 'DISETUJUI' && $action === 'APPROVE') || ($current === 'DITOLAK' && $action === 'REJECT')) {
                throw ValidationException::withMessages(['status' => 'PPB ini sudah '.strtolower($current).'.']);
            }
            DB::table(self::TABLE)->insert([
                'no_ppb' => $noPpb, 'action' => $action, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
            ]);
            $action === 'APPROVE' ? $this->addToPoTable($noPpb, $user) : $this->removeFromPoTable($noPpb);
        });
    }

    /**
     * STOCKWISE PPB: Manager approves (APPROVE_MANAGER), then the Direktur (APPROVE → "Menunggu PO").
     * Either may reject at their step (with a reason); the Direktur may still withdraw an approval
     * while its PO is not made yet.
     */
    /**
     * Admin revised a STOCKWISE PPB that was sent back (its quantities, with a note when changed):
     * it goes to the Manager again (the Direktur may approve it straight away).
     *
     * @param  array<int, float>  $quantities  ppb row id => qty
     */
    public function resubmit(string $noPpb, User $user, array $quantities, ?string $note): void
    {
        DB::transaction(function () use ($noPpb, $user, $quantities, &$note) {
            DB::table(self::TABLE)->where('no_ppb', $noPpb)->lockForUpdate()->get(['id']);
            if (! self::isStockwise($noPpb) || (self::statusFor(collect([$noPpb]))[$noPpb]['status'] ?? null) !== 'DIKEMBALIKAN') {
                throw ValidationException::withMessages(['status' => 'PPB ini tidak sedang dikembalikan untuk revisi.']);
            }
            $rows = Ppb::where('no_ppb', $noPpb)->get();
            $changed = \App\Support\ApprovalQuantities::changes($rows, $quantities, $note);
            $note = \App\Support\ApprovalQuantities::note($rows, $changed, $note) ?? 'Direvisi admin';
            foreach ($changed as $id => $q) {
                Ppb::whereKey($id)->update(['kuantitas' => $q, 'line_status' => \App\Support\PpbLineStatus::of($q, null, false)]);
                DB::table('ppb_draft_lines')->where('ppb_id', $id)->update(['qty' => $q, 'updated_at' => now()]);
            }
            DB::table(self::TABLE)->insert(['no_ppb' => $noPpb, 'action' => 'DIAJUKAN_ULANG', 'note' => $note, 'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now()]);
        });
        $link = '/approvals';
        NotificationDispatcher::toPermission('ppb.approve_manager', 'ppb', 'info', "PPB {$noPpb} diajukan ulang", "Sudah direvisi {$user->name}: {$note}", $link, $user->id);
        NotificationDispatcher::toPermission('ppb.approve_director', 'ppb', 'info', "PPB {$noPpb} diajukan ulang (menunggu Manajer)",
            "Sudah direvisi {$user->name}. Anda bisa langsung approve (bypass).", $link, $user->id);
    }

    private function decideStockwise(string $noPpb, User $user, string $action, ?string $note, array $quantities = []): void
    {
        $next = null;
        $bypassed = false;
        DB::transaction(function () use ($noPpb, $user, $action, &$note, $quantities, &$next, &$bypassed) {
            DB::table(self::TABLE)->where('no_ppb', $noPpb)->lockForUpdate()->get(['id']);
            if ($action === 'APPROVE' && $quantities !== []) {
                // the approver changed quantities: the PPB rows (and the Draft PPB lines the requests follow) take them
                $rows = Ppb::where('no_ppb', $noPpb)->get();
                $changed = \App\Support\ApprovalQuantities::changes($rows, $quantities, $note);
                $note = \App\Support\ApprovalQuantities::note($rows, $changed, $note);
                foreach ($changed as $id => $q) {
                    Ppb::whereKey($id)->update(['kuantitas' => $q, 'line_status' => \App\Support\PpbLineStatus::of($q, null, false)]);
                    DB::table('ppb_draft_lines')->where('ppb_id', $id)->update(['qty' => $q, 'updated_at' => now()]);
                }
            }
            $status = self::statusFor(collect([$noPpb]))[$noPpb]['status'];
            $isManager = $user->hasPermission('ppb.approve_manager');
            $isDirector = $user->hasPermission('ppb.approve_director');
            $log = fn (string $a, ?string $n) => DB::table(self::TABLE)->insert([
                'no_ppb' => $noPpb, 'action' => $a, 'note' => $n !== null && trim($n) !== '' ? trim($n) : null,
                'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
            ]);

            if ($status === 'DIKEMBALIKAN') {
                throw ValidationException::withMessages(['status' => 'PPB ini sedang direvisi admin, tunggu diajukan ulang.']);
            }
            if ($action === 'RETURN') {
                // back to admin for a revision: whoever may act on this step, with the reason
                if (trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => 'Tulis catatan apa yang perlu direvisi.']);
                }
                $mayAct = ($status === 'MENUNGGU' && ($isManager || $isDirector)) || ($status === 'MENUNGGU_DIREKTUR' && $isDirector);
                if (! $mayAct) {
                    throw ValidationException::withMessages(['status' => 'PPB ini tidak sedang menunggu approval Anda.']);
                }
                $log('RETURN', $note);
                $next = 'DIKEMBALIKAN';

                return;
            }
            if (in_array($status, ['MENUNGGU', 'DITOLAK'], true)) {
                if (! $isManager && ! $isDirector) {
                    throw ValidationException::withMessages(['status' => 'PPB ini menunggu approval Manager.']);
                }
                if ($action === 'REJECT') {
                    if ($status === 'DITOLAK') {
                        throw ValidationException::withMessages(['status' => 'PPB ini sudah ditolak.']);
                    }
                    $log('REJECT', $note);
                    $next = 'DITOLAK';
                } elseif ($isManager) {
                    $log('APPROVE_MANAGER', $note);
                    $next = 'MENUNGGU_DIREKTUR';
                } else {
                    // a Direktur at the Manager step: the Manager step is approved for them
                    $log('APPROVE_MANAGER', "Disetujui otomatis: Direktur {$user->name} langsung approve (bypass)");
                    $log('APPROVE', $note);
                    $this->addToPoTable($noPpb, $user);
                    $bypassed = true;
                    $next = 'DISETUJUI';
                }
            } elseif ($status === 'MENUNGGU_DIREKTUR' || $status === 'DISETUJUI') {
                if (! $isDirector) {
                    throw ValidationException::withMessages(['status' => $status === 'DISETUJUI' ? 'PPB ini sudah disetujui.' : 'PPB ini menunggu approval Direktur.']);
                }
                if ($action === 'APPROVE') {
                    if ($status === 'DISETUJUI') {
                        throw ValidationException::withMessages(['status' => 'PPB ini sudah disetujui.']);
                    }
                    $log('APPROVE', $note);
                    $this->addToPoTable($noPpb, $user);
                    $next = 'DISETUJUI';
                } else {
                    // a PO already made of its lines (from the Draft PO, maybe together with other PPBs' lines)
                    $made = PurchaseOrderItem::whereIn('approved_ppb_id', Ppb::where('no_ppb', $noPpb)->pluck('id'))
                        ->whereHas('purchaseOrder', fn ($p) => $p->whereNotIn('status', [self::WAITING, 'CANCELLED']))->exists()
                        || PurchaseOrder::where('from_ppb_approval', $noPpb)->whereNotIn('status', [self::WAITING, 'CANCELLED'])->exists();
                    if ($made) {
                        throw ValidationException::withMessages(['status' => 'PO untuk PPB ini sudah dibuat — batalkan PO-nya dulu.']);
                    }
                    $log('REJECT', $note);
                    $this->removeFromPoTable($noPpb);
                    $next = 'DITOLAK';
                }
            } else {
                throw ValidationException::withMessages(['status' => 'PPB ini tidak perlu approval.']);
            }
        });

        $link = '/ppb/'.Ppb::where('no_ppb', $noPpb)->value('id');
        if ($bypassed) {
            NotificationDispatcher::toPermission('ppb.approve_manager', 'ppb', 'info', "PPB {$noPpb} disetujui langsung oleh Direktur",
                "Tahap Manajer disetujui otomatis (bypass oleh {$user->name}).", $link, $user->id);
        }
        match ($next) {
            'DIKEMBALIKAN' => NotificationDispatcher::toPermission('ppb.create', 'ppb', 'warning', "PPB {$noPpb} dikembalikan untuk revisi",
                "{$user->name}: ".trim((string) $note), $link, $user->id),
            'MENUNGGU_DIREKTUR' => NotificationDispatcher::toPermission('ppb.approve_director', 'ppb', 'info', "PPB {$noPpb} menunggu approval Direktur",
                "Sudah disetujui Manager ({$user->name}).", $link, $user->id),
            'DISETUJUI' => NotificationDispatcher::toPermission('po.create', 'ppb', 'success', "PPB {$noPpb} disetujui",
                'Barangnya masuk Draft PO: bandingkan harga vendor, lalu pilih barang untuk dijadikan PO.', '/purchase-orders/draft', $user->id),
            'DITOLAK' => NotificationDispatcher::toPermission('ppb.create', 'ppb', 'warning', "PPB {$noPpb} ditolak",
                "{$user->name}: ".trim((string) $note), $link, $user->id),
            default => null,
        };
        if ($next === 'DITOLAK') {
            // Request Barang whose goods were to be bought by this PPB: no longer in procurement
            $ids = Ppb::where('no_ppb', $noPpb)->pluck('id');
            $requests = \App\Models\ItemRequest::whereIn('id', DB::table('item_request_lines')->whereIn('ppb_id', $ids)->pluck('item_request_id'))
                ->whereIn('status', \App\Models\ItemRequest::FULFILMENT_STATUSES)->get();
            foreach ($requests as $r) {
                app(ItemRequestFulfillmentService::class)->recompute($r);
                NotificationDispatcher::toPermission(ItemRequestApprovalService::HANDOVER_PERMISSION, 'request', 'warning', "PPB {$noPpb} ditolak",
                    "Barang untuk request {$r->number} tidak jadi dibeli — tentukan: serahkan dari stok atau tutup sisanya.", "/item-requests/{$r->id}");
            }
        }
    }

    /** The PO-table entry an approval made for this PPB: still "Menunggu PO", or the STOCKWISE PO its lines became (null = none). */
    public static function poEntry(string $noPpb): ?PurchaseOrder
    {
        $lines = Ppb::where('no_ppb', $noPpb)->pluck('id');

        return PurchaseOrder::where('status', '!=', 'CANCELLED')
            ->where(fn ($q) => $q->where('from_ppb_approval', $noPpb)->orWhereHas('items', fn ($i) => $i->whereIn('approved_ppb_id', $lines)))
            ->orderByDesc('id')->first(['id', 'number', 'status']);
    }

    /** APPROVE: the PPB lines no PO covers yet go into the PO table as one "Menunggu PO" entry. */
    private function addToPoTable(string $noPpb, User $user): void
    {
        if (self::poEntry($noPpb)) {
            return;
        }
        $stockwise = self::isStockwise($noPpb);
        $lines = Ppb::where('no_ppb', $noPpb)->whereDoesntHave('poItems')->orderBy('id')->get();
        if ($lines->isEmpty()) {
            return; // Accurate already has a PO for every line
        }
        $date = now();
        $number = $this->numbers->next('PO', 'PPB', $date);
        preg_match('/(\d+)$/', $number, $m);
        $po = PurchaseOrder::create([
            'number' => $number, 'prefix' => 'PPB', 'year' => (int) $date->format('y'), 'month' => (int) $date->format('n'), 'sequence' => (int) $m[1],
            'date' => $date->toDateString(), 'vendor_id' => null, 'site_id' => $user->site_id ?? DB::table('sites')->value('id'),
            'status' => self::WAITING, 'subtotal' => 0, 'tax' => 0, 'total' => 0,
            'notes' => $stockwise
                ? "Dari PPB {$noPpb} (STOCKWISE), disetujui {$user->name}. Lengkapi vendor & harga untuk jadi PO STOCKWISE."
                : "Dari PPB {$noPpb}, disetujui {$user->name}. Vendor & harga mengikuti PO di Accurate.",
            'from_ppb_approval' => $noPpb, 'approved_by' => $user->id, 'approved_at' => $date, 'created_by' => $user->id,
        ]);
        $units = DB::table('units')->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id]);
        $items = Item::whereIn('code', $lines->pluck('kode_barang')->filter())->pluck('id', 'code');
        foreach ($lines as $l) {
            $po->items()->create([
                'approved_ppb_id' => $l->id, 'item_id' => $items[$l->kode_barang] ?? null,
                'description_raw' => mb_substr((string) ($l->deskripsi_barang ?: $l->kode_barang ?: '-'), 0, 400),
                'qty' => $l->kuantitas ?? 0, 'unit_id' => $l->satuan ? ($units[mb_strtoupper($l->satuan)] ?? null) : null,
                'unit_price' => 0, 'line_total' => 0, 'qty_received' => 0, 'line_status' => self::WAITING,
            ]);
        }
    }

    /** REJECT after an approval: its "Menunggu PO" lines leave the PO table (and the Draft PO). */
    private function removeFromPoTable(string $noPpb): void
    {
        $waiting = PurchaseOrderItem::whereIn('approved_ppb_id', Ppb::where('no_ppb', $noPpb)->pluck('id'))->where('line_status', self::WAITING)
            ->whereHas('purchaseOrder', fn ($p) => $p->where('status', self::WAITING));
        $poIds = (clone $waiting)->pluck('purchase_order_id')->unique();
        $waiting->delete();
        PurchaseOrder::whereIn('id', $poIds)->where('status', self::WAITING)->doesntHave('items')->get()->each->delete();
        PurchaseOrder::where('from_ppb_approval', $noPpb)->where('status', self::WAITING)->get()->each->delete();
    }

    /** "Menunggu PO" lines whose PPB line now has Accurate's real PO go; an entry left empty goes too. */
    public function clearOrderedEntries(): int
    {
        $done = PurchaseOrderItem::query()->where('line_status', self::WAITING)
            ->whereHas('approvedPpb', fn ($p) => $p->whereHas('poItems'))->get();
        $poIds = $done->pluck('purchase_order_id')->unique();
        PurchaseOrderItem::whereIn('id', $done->pluck('id'))->delete();
        PurchaseOrder::whereIn('id', $poIds)->where('status', self::WAITING)->doesntHave('items')->get()->each->delete();

        return $done->count();
    }

    /**
     * After every sync: log + notify the approvers of each new PPB to approve,
     * and of a PO Accurate made for a PPB nobody approved (once per PPB).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    public function scan(): array
    {
        $logged = DB::table(self::TABLE)->whereIn('action', ['DIAJUKAN', 'PO_TANPA_APPROVAL'])->get(['no_ppb', 'action'])
            ->groupBy('action')->map(fn ($g) => $g->pluck('no_ppb')->flip());
        $docs = Ppb::query()->whereNotNull('no_ppb')->whereDate('tgl_ppb', '>=', self::GO_LIVE)
            ->selectRaw('no_ppb, MIN(tgl_ppb) as tgl, COUNT(*) as line_count, MAX(peminta) as peminta')->groupBy('no_ppb')->get();
        $now = now();
        $inserted = 0;

        $new = $docs->reject(fn ($d) => isset($logged['DIAJUKAN'][$d->no_ppb]));
        foreach ($new as $d) {
            DB::table(self::TABLE)->insert(['no_ppb' => $d->no_ppb, 'action' => 'DIAJUKAN', 'note' => null, 'user_name' => 'Sistem', 'created_at' => $now]);
            $inserted++;
        }
        // STOCKWISE PPBs go to the Manager first; Accurate's to the one-step approvers
        $sw = self::stockwiseNos($new->pluck('no_ppb'))->flip();
        // the Direktur is told about the STOCKWISE ones at once too: they may approve them straight away (bypass)
        $mine = $new->filter(fn ($d) => $sw->has($d->no_ppb));
        foreach ([[$new->reject(fn ($d) => $sw->has($d->no_ppb)), 'ppb.approve'], [$mine, 'ppb.approve_manager'], [$mine, 'ppb.approve_director']] as [$group, $perm]) {
            if ($group->isNotEmpty()) {
                $direktur = $perm === 'ppb.approve_director';
                NotificationDispatcher::toPermission($perm, 'ppb', 'info', ($group->count() === 1 ? "PPB {$group->first()->no_ppb} menunggu approval" : "{$group->count()} PPB menunggu approval").($direktur ? ' Manajer' : ''),
                    $group->take(5)->map(fn ($d) => "{$d->no_ppb} ({$d->line_count} barang".($d->peminta ? ", {$d->peminta}" : '').')')->implode('; ').($direktur ? '. Anda bisa langsung approve (bypass).' : ''),
                    $perm === 'ppb.approve' ? '/ppb?approval=MENUNGGU' : '/approvals');
            }
        }

        // a PO in Accurate for a PPB that was not approved
        $withPo = Ppb::query()->whereIn('no_ppb', $docs->pluck('no_ppb'))->whereHas('poItems')->distinct()->pluck('no_ppb');
        $status = self::statusFor($withPo);
        foreach ($withPo as $no) {
            if (isset($logged['PO_TANPA_APPROVAL'][$no]) || ($status[$no]['status'] ?? null) === 'DISETUJUI') {
                continue;
            }
            $po = Ppb::where('no_ppb', $no)->with('poItems.purchaseOrder:id,number')->get()->flatMap->poItems->pluck('purchaseOrder.number')->filter()->unique()->implode(', ');
            DB::table(self::TABLE)->insert(['no_ppb' => $no, 'action' => 'PO_TANPA_APPROVAL', 'note' => "PO {$po} dibuat di Accurate sebelum PPB di-approve", 'user_name' => 'Sistem', 'created_at' => $now]);
            NotificationDispatcher::toPermission('ppb.approve', 'ppb', 'danger', "PO tanpa approval: {$no}",
                "PO {$po} dibuat di Accurate untuk PPB {$no} yang belum di-approve.", '/ppb?approval=PO_TANPA_APPROVAL');
            $inserted++;
        }

        // Accurate's real PO arrived: the approval's "Menunggu PO" lines for it leave the PO table
        $cleared = $this->clearOrderedEntries();

        // approved PPBs whose lines still have no PO and no "Menunggu PO" entry (e.g. approved before entries existed) get one
        $approved = DB::table(self::TABLE.' as a')->where('a.action', 'APPROVE')
            ->whereRaw('a.id = (select max(b.id) from '.self::TABLE." b where b.no_ppb = a.no_ppb and b.action in ('APPROVE', 'REJECT', 'APPROVE_MANAGER'))")
            ->get(['a.no_ppb', 'a.user_id']);
        foreach ($approved as $a) {
            $approver = $a->user_id ? User::find($a->user_id) : null;
            if ($approver && ! self::poEntry($a->no_ppb)) {
                $this->addToPoTable($a->no_ppb, $approver);
            }
        }

        return ['total' => $docs->count(), 'inserted' => $inserted, 'updated' => $cleared, 'skipped' => $docs->count() - $new->count(), 'errors' => 0];
    }
}
