<?php

namespace App\Support;

use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\Ri;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * No document made twice for the same goods — e.g. admin A and admin B both entering the one request
 * they were told about. Before a manual NPBG / PPB / PO / RI is saved, a recent one for the same item
 * (and the same customer / requester / vendor / delivery note) is looked for:
 *   - the same line of the same document (PPB number, RI delivery note) → refused, always;
 *   - a recent look-alike → refused with what was found ("duplicate"), unless the user confirms it
 *     is really another one (allow_duplicate).
 * Both admins saving at once: the check and the save run under one lock per item.
 */
final class DuplicateGuard
{
    /** how far back a look-alike counts */
    public const DAYS = 3;

    /** Runs $save under a lock per document kind + item, so two saves of the same goods never pass the check together. */
    public static function locked(string $kind, ?string $item, Closure $save): mixed
    {
        return Cache::lock('dup:'.$kind.':'.md5(mb_strtolower(trim((string) $item))), 15)->block(10, $save);
    }

    /** "the same" item: its code when there is one, else its description */
    private static function sameItem($q, ?string $kode, ?string $deskripsi, string $kodeCol = 'kode_barang', string $descCol = 'deskripsi_barang')
    {
        return filled($kode) ? $q->where($kodeCol, trim($kode)) : $q->whereRaw("LOWER(TRIM({$descCol})) = ?", [mb_strtolower(trim((string) $deskripsi))]);
    }

    private static function by(?int $userId): string
    {
        $name = $userId ? User::whereKey($userId)->value('name') : null;

        return $name ? " oleh {$name}" : '';
    }

    private static function when(?Carbon $at): string
    {
        return $at ? ' '.$at->timezone(config('app.timezone'))->format('d/m H:i') : '';
    }

    /** Refuses a look-alike unless confirmed; a hard duplicate always. */
    public static function refuse(?string $found, bool $allow, bool $hard = false): void
    {
        if ($found !== null && ($hard || ! $allow)) {
            throw ValidationException::withMessages(['duplicate' => $found.($hard ? '' : ' Kalau memang barang / permintaan lain, konfirmasi "Tetap simpan".')]);
        }
    }

    /** Manual NPBG: the same item for the same customer, not rejected, made the last days. */
    public static function npbg(array $d): ?string
    {
        $q = self::sameItem(Npbg::query(), $d['kode_barang'] ?? null, $d['deskripsi_barang'] ?? null)
            ->whereRaw('LOWER(TRIM(pelanggan)) = ?', [mb_strtolower(trim((string) ($d['pelanggan'] ?? '')))])
            ->where('created_at', '>=', now()->subDays(self::DAYS));
        foreach ($q->orderByDesc('id')->limit(5)->get() as $n) {
            $status = $n->no_npbg ? (\App\Services\NpbgApprovalService::statusFor(collect([$n->no_npbg]))[$n->no_npbg]['status'] ?? null) : null;
            if ($status === 'DITOLAK') {
                continue;
            }

            return "Sudah ada NPBG {$n->no_npbg} untuk {$n->deskripsi_barang} ({$n->kuantitas} {$n->satuan}) ke {$n->pelanggan}, dibuat".self::by($n->created_by).self::when($n->created_at).'.';
        }

        return null;
    }

    /** Manual PPB: the same line in the same PPB number (hard), or the same item for the same requester lately. */
    public static function ppb(array $d): array
    {
        $same = self::sameItem(Ppb::query(), $d['kode_barang'] ?? null, $d['deskripsi_barang'] ?? null);
        if ($p = (clone $same)->where('no_ppb', trim((string) $d['no_ppb']))->first()) {
            return ["Barang {$p->deskripsi_barang} sudah ada di PPB {$p->no_ppb}.", true];
        }
        $p = (clone $same)->whereRaw("LOWER(TRIM(COALESCE(peminta, ''))) = ?", [mb_strtolower(trim((string) ($d['peminta'] ?? '')))])
            ->where('created_at', '>=', now()->subDays(self::DAYS))->where('status', '!=', 'CLOSED')->orderByDesc('id')->first();

        return $p ? ["Sudah ada PPB {$p->no_ppb} untuk {$p->deskripsi_barang} ({$p->kuantitas} {$p->satuan})".(filled($p->peminta) ? " peminta {$p->peminta}" : '').', dibuat'.self::by($p->created_by).self::when($p->created_at).'.', false] : [null, false];
    }

    /** Manual PO: an open PO to the same vendor with the same items lately. */
    public static function po(array $d): ?string
    {
        $keys = collect($d['items'] ?? [])->map(fn ($i) => ! empty($i['item_id']) ? 'i'.$i['item_id'] : 'd'.mb_strtolower(trim((string) $i['description_raw'])))->sort()->values()->all();
        $recent = PurchaseOrder::with('items:id,purchase_order_id,item_id,description_raw')->where('vendor_id', $d['vendor_id'] ?? 0)->whereNull('accurate_po_id')
            ->whereNotIn('status', ['CANCELLED'])->where('created_at', '>=', now()->subDays(self::DAYS))->orderByDesc('id')->get();
        foreach ($recent as $po) {
            $has = $po->items->map(fn ($i) => $i->item_id ? 'i'.$i->item_id : 'd'.mb_strtolower(trim((string) $i->description_raw)))->sort()->values()->all();
            if ($has === $keys) {
                return "Sudah ada PO {$po->number} ke vendor yang sama dengan barang yang sama, dibuat".self::by($po->created_by).self::when($po->created_at).'.';
            }
        }

        return null;
    }

    /** Manual RI: the same item on the same delivery note (hard), or the same item, qty and vendor the same day. */
    public static function ri(array $d): array
    {
        $same = self::sameItem(Ri::query(), $d['kode_barang'] ?? null, $d['deskripsi_barang'] ?? null);
        $sj = trim((string) ($d['no_surat_jalan'] ?? ''));
        if ($sj !== '' && ($r = (clone $same)->where('no_surat_jalan', $sj)->first())) {
            return ["Barang {$r->deskripsi_barang} dari surat jalan {$sj} sudah diterima di RI {$r->no_ri}.", true];
        }
        if ($sj !== '') {
            return [null, false]; // another delivery note: another delivery (e.g. the rest of a partial PO)
        }
        $r = (clone $same)->whereDate('tgl_ri', $d['tgl_ri'] ?? now()->toDateString())->where('kuantitas', (float) $d['kuantitas'])
            ->whereRaw("LOWER(TRIM(COALESCE(vendor, ''))) = ?", [mb_strtolower(trim((string) ($d['vendor'] ?? '')))])->orderByDesc('id')->first();

        return $r ? ["Sudah ada RI {$r->no_ri} untuk {$r->deskripsi_barang} ({$r->kuantitas} {$r->satuan}) di tanggal yang sama, dibuat".self::by($r->created_by).self::when($r->created_at).'.', false] : [null, false];
    }
}
