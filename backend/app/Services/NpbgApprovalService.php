<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Npbg;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A manual NPBG ("Tambah NPBG" — not from Accurate, not given for a Request Barang, which was approved
 * already) is approved in two steps before the goods leave the stock:
 *   MENUNGGU → Manager (npbg.approve_manager, log APPROVE_MANAGER) → MENUNGGU_DIREKTUR
 *            → Direktur (npbg.approve_director, log APPROVE) → DISETUJUI: the stock goes out (STOCK_OUT)
 *   either step may reject (REJECT, with a reason) → DITOLAK.
 * A Direktur without the Manager right approves both steps at once (bypass). Log: npbg_approvals.
 */
class NpbgApprovalService
{
    public const TABLE = 'npbg_approvals';

    /** no_npbg of the given ones that need this approval (manual, not from a Request Barang) */
    public static function manualNos(Collection $noNpbgs): Collection
    {
        $nos = $noNpbgs->filter()->unique()->values();
        if ($nos->isEmpty()) {
            return collect();
        }

        return Npbg::query()->whereIn('no_npbg', $nos)->whereNull('accurate_arinvoice_id')->whereNotNull('created_by')
            ->whereNotIn('id', DB::table('item_request_fulfillment_lines')->whereNotNull('npbg_id')->select('npbg_id'))
            ->distinct()->pluck('no_npbg');
    }

    /** @return array<string, array{status:string, step:?string, at:?string, by:?string, note:?string}> */
    public static function statusFor(Collection $noNpbgs): array
    {
        $manual = self::manualNos($noNpbgs);
        if ($manual->isEmpty()) {
            return [];
        }
        $log = DB::table(self::TABLE)->whereIn('no_npbg', $manual)->orderBy('id')->get()->groupBy('no_npbg');

        return $manual->mapWithKeys(function ($no) use ($log) {
            $last = $log->get($no, collect())->last();
            $status = match ($last?->action) {
                'APPROVE' => 'DISETUJUI', 'REJECT' => 'DITOLAK', 'APPROVE_MANAGER' => 'MENUNGGU_DIREKTUR',
                // sent back to admin for a revision; DIAJUKAN_ULANG (revised) waits for the Manager again
                'RETURN' => 'DIKEMBALIKAN', default => 'MENUNGGU',
            };

            return [$no => ['status' => $status, 'step' => ['MENUNGGU' => 'MANAGER', 'MENUNGGU_DIREKTUR' => 'DIREKTUR', 'DIKEMBALIKAN' => 'ADMIN'][$status] ?? null,
                'at' => $last?->created_at, 'by' => $last?->user_name, 'note' => $last?->note]];
        })->all();
    }

    public static function log(string $noNpbg): Collection
    {
        return DB::table(self::TABLE)->where('no_npbg', $noNpbg)->orderBy('id')->get(['id', 'action', 'note', 'user_name', 'created_at']);
    }

    public static function canDecide(User $user, ?array $status): bool
    {
        return match ($status['status'] ?? null) {
            'MENUNGGU' => $user->hasPermission('npbg.approve_manager') || $user->hasPermission('npbg.approve_director'),
            'MENUNGGU_DIREKTUR' => $user->hasPermission('npbg.approve_director'),
            default => false,
        };
    }

    /** A manual NPBG was made: the Manager is told. */
    public function submitted(Npbg $npbg, User $by): void
    {
        NotificationDispatcher::toPermission('npbg.approve_manager', 'npbg', 'info', "NPBG {$npbg->no_npbg} menunggu approval",
            "{$npbg->deskripsi_barang} {$npbg->kuantitas} {$npbg->satuan} untuk {$npbg->pelanggan}.", "/approvals?sel=NPBG-{$npbg->id}", $by->id);
        // the Direktur sees it at once too and may approve it straight away (bypass)
        NotificationDispatcher::toPermission('npbg.approve_director', 'npbg', 'info', "NPBG {$npbg->no_npbg} menunggu approval Manajer",
            "{$npbg->deskripsi_barang} {$npbg->kuantitas} {$npbg->satuan}. Anda bisa langsung approve (bypass).", "/approvals?sel=NPBG-{$npbg->id}", $by->id);
    }

    /** @param  array<int, float>  $quantities  npbg row id => the approved qty (a note is required when any changed) */
    /**
     * Admin revised a manual NPBG that was sent back (its quantities, with a note when changed):
     * it goes to the Manager again.
     *
     * @param  array<int, float>  $quantities  npbg row id => qty
     */
    public function resubmit(Npbg $npbg, User $user, array $quantities, ?string $note): void
    {
        $no = (string) $npbg->no_npbg;
        DB::transaction(function () use ($no, $user, $quantities, &$note) {
            DB::table(self::TABLE)->where('no_npbg', $no)->lockForUpdate()->get(['id']);
            if ((self::statusFor(collect([$no]))[$no]['status'] ?? null) !== 'DIKEMBALIKAN') {
                throw ValidationException::withMessages(['status' => 'NPBG ini tidak sedang dikembalikan untuk revisi.']);
            }
            $rows = Npbg::where('no_npbg', $no)->get();
            $changed = \App\Support\ApprovalQuantities::changes($rows, $quantities, $note);
            $note = \App\Support\ApprovalQuantities::note($rows, $changed, $note) ?? 'Direvisi admin';
            foreach ($changed as $id => $q) {
                Npbg::whereKey($id)->update(['kuantitas' => $q]);
            }
            DB::table(self::TABLE)->insert(['no_npbg' => $no, 'action' => 'DIAJUKAN_ULANG', 'note' => $note, 'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now()]);
        });
        $link = "/approvals?sel=NPBG-{$npbg->id}";
        NotificationDispatcher::toPermission('npbg.approve_manager', 'npbg', 'info', "NPBG {$no} diajukan ulang", "Sudah direvisi {$user->name}: {$note}", $link, $user->id);
        NotificationDispatcher::toPermission('npbg.approve_director', 'npbg', 'info', "NPBG {$no} diajukan ulang (menunggu Manajer)",
            "Sudah direvisi {$user->name}. Anda bisa langsung approve (bypass).", $link, $user->id);
    }

    public function decide(Npbg $npbg, User $user, string $action, ?string $note, array $quantities = []): void
    {
        $no = (string) $npbg->no_npbg;
        if ($action === 'REJECT' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Alasan tolak wajib diisi.']);
        }
        $next = null;
        $bypassed = false;
        DB::transaction(function () use ($npbg, $no, $user, $action, &$note, $quantities, &$next, &$bypassed) {
            DB::table(self::TABLE)->where('no_npbg', $no)->lockForUpdate()->get(['id']);
            if ($action === 'APPROVE' && $quantities !== [] && self::canDecide($user, self::statusFor(collect([$no]))[$no] ?? null)) {
                // the approver changed quantities: the NPBG takes them (the stock leaves with the final approval)
                $rows = Npbg::where('no_npbg', $no)->get();
                $changed = \App\Support\ApprovalQuantities::changes($rows, $quantities, $note);
                $note = \App\Support\ApprovalQuantities::note($rows, $changed, $note);
                foreach ($changed as $id => $q) {
                    Npbg::whereKey($id)->update(['kuantitas' => $q]);
                }
            }
            $status = self::statusFor(collect([$no]))[$no]['status'] ?? null;
            if (! $status) {
                throw ValidationException::withMessages(['status' => 'NPBG ini tidak perlu approval.']);
            }
            if (! self::canDecide($user, ['status' => $status])) {
                throw ValidationException::withMessages(['status' => match ($status) {
                    'MENUNGGU' => 'NPBG ini menunggu approval Manager.',
                    'MENUNGGU_DIREKTUR' => 'NPBG ini menunggu approval Direktur.',
                    'DIKEMBALIKAN' => 'NPBG ini sedang direvisi admin, tunggu diajukan ulang.',
                    default => 'NPBG ini sudah '.($status === 'DISETUJUI' ? 'disetujui' : 'ditolak').'.',
                }]);
            }
            $log = fn (string $a, ?string $n) => DB::table(self::TABLE)->insert([
                'no_npbg' => $no, 'action' => $a, 'note' => $n !== null && trim($n) !== '' ? trim($n) : null,
                'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
            ]);
            if ($action === 'RETURN') {
                // back to admin for a revision, with the reason
                if (trim((string) $note) === '') {
                    throw ValidationException::withMessages(['note' => 'Tulis catatan apa yang perlu direvisi.']);
                }
                $log('RETURN', $note);
                $next = 'DIKEMBALIKAN';

                return;
            }
            if ($action === 'REJECT') {
                $log('REJECT', $note);
                $next = 'DITOLAK';

                return;
            }
            if ($status === 'MENUNGGU' && $user->hasPermission('npbg.approve_manager')) {
                $log('APPROVE_MANAGER', $note);
                $next = 'MENUNGGU_DIREKTUR';

                return;
            }
            if ($status === 'MENUNGGU') {
                $log('APPROVE_MANAGER', "Disetujui otomatis: Direktur {$user->name} langsung approve (bypass)");
                $bypassed = true;
            }
            $this->issue($no, $user);
            $log('APPROVE', $note);
            $next = 'DISETUJUI';
        });

        $link = "/npbg/{$npbg->id}";
        if ($next === 'DIKEMBALIKAN') {
            NotificationDispatcher::toUser($npbg->created_by, 'npbg', 'warning', "NPBG {$no} dikembalikan untuk revisi", "{$user->name}: ".trim((string) $note), $link);
        }
        if ($bypassed) {
            NotificationDispatcher::toPermission('npbg.approve_manager', 'npbg', 'info', "NPBG {$no} disetujui langsung oleh Direktur",
                "Tahap Manajer disetujui otomatis (bypass oleh {$user->name}).", $link, $user->id);
        }
        match ($next) {
            'MENUNGGU_DIREKTUR' => NotificationDispatcher::toPermission('npbg.approve_director', 'npbg', 'info', "NPBG {$no} menunggu approval Direktur",
                "Sudah disetujui Manager ({$user->name}).", "/approvals?sel=NPBG-{$npbg->id}", $user->id),
            'DISETUJUI' => NotificationDispatcher::toUser($npbg->created_by, 'npbg', 'success', "NPBG {$no} disetujui", 'Barang dikeluarkan dari stok.', $link),
            'DITOLAK' => NotificationDispatcher::toUser($npbg->created_by, 'npbg', 'warning', "NPBG {$no} ditolak", "{$user->name}: ".trim((string) $note), $link),
            default => null,
        };
    }

    /** Approved: its goods leave the stock (an item of the Master Produk; a Jasa / unknown code has none). */
    private function issue(string $no, User $user): void
    {
        $warehouseId = Warehouse::query()->orderBy('id')->value('id');
        foreach (Npbg::where('no_npbg', $no)->get() as $row) {
            $item = $row->kode_barang ? Item::where('code', $row->kode_barang)->first() : null;
            if (! $item || $item->product_kind === 'JASA' || ! $warehouseId) {
                continue;
            }
            $have = (float) DB::table('inventory')->where('item_id', $item->id)->sum(DB::raw('actual_qty - reserved_qty'));
            if ((float) $row->kuantitas > $have + 0.0001) {
                throw ValidationException::withMessages(['status' => "Stok {$item->description} hanya ".rtrim(rtrim(number_format($have, 2, ',', '.'), '0'), ',').' — tidak cukup untuk NPBG ini.']);
            }
            $left = (float) $row->kuantitas;
            foreach (DB::table('inventory')->where('item_id', $item->id)->orderByRaw('(actual_qty - reserved_qty) DESC')->get() as $inv) {
                $take = min($left, (float) $inv->actual_qty - (float) $inv->reserved_qty);
                if ($take <= 0) {
                    continue;
                }
                app(StockLedgerService::class)->record(['type' => 'STOCK_OUT', 'item_id' => $item->id, 'warehouse_id' => $inv->warehouse_id, 'qty' => $take,
                    'reference' => $row, 'note' => "NPBG {$no} (disetujui)", 'created_by' => $user->id]);
                $left = round($left - $take, 2);
                if ($left <= 0) {
                    break;
                }
            }
        }
    }
}
