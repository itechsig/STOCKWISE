<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\ItemRequest;
use App\Models\ItemRequestFulfillment;
use App\Models\ItemRequestLine;
use App\Models\Npbg;
use App\Models\PpbDraft;
use App\Models\User;
use App\Services\Inventory\StockLedgerService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tracking\TrackingPhotoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * After the approvals a Request Barang is fulfilled from real stock, maybe in several steps:
 *
 *   prepare()   Gudang sets how much of each line it gives now (≤ what is left, ≤ the stock not taken
 *               by other requests). That becomes ONE NPBG (#1, #2 = NPBG lanjutan …) and leaves the
 *               stock at once (StockLedgerService STOCK_OUT); what cannot be given may go to a Draft PPB.
 *   confirm()   Gudang fixes how much of that NPBG it really hands over (less goes back to stock) → SIAP
 *   handover()  serah terima of that NPBG: photo, the receiver's signature and the quantities really
 *               handed over (less than prepared goes back to stock).
 *   close()     what is left of lines is closed officially, with a reason.
 *   recompute() the request status from its lines: SELESAI only when nothing is left.
 *
 * The approved quantity never changes here — only what was given and what is left.
 */
class ItemRequestFulfillmentService
{
    public const PHOTO_ENTITY = 'item_request_fulfillment';

    /** a fulfilment not handed over yet: DISIAPKAN (Gudang still fixes the quantities) or SIAP (ready for the serah terima) */
    public const OPEN = ['DISIAPKAN', 'SIAP'];

    public function __construct(
        private DocumentNumberService $numbers,
        private StockLedgerService $ledger,
        private TrackingPhotoService $photos,
        private ItemRequestApprovalService $approvals,
    ) {}

    /** Stock STOCKWISE can give of an item: actual − reserved over its warehouses (null when unknown). */
    public static function available(?int $itemId): ?float
    {
        if (! $itemId) {
            return null;
        }
        $row = Inventory::where('item_id', $itemId)->selectRaw('SUM(actual_qty - reserved_qty) a, MAX(stock_known) k')->first();

        return $row && $row->a !== null ? round(max(0.0, (float) $row->a), 2) : null;
    }

    /**
     * Live stock of several items at once, straight from `inventory` (what NPBG / RI / the sync just moved):
     * item id => available (actual − reserved); an item without any stock row is not in the result (unknown).
     *
     * @param  iterable<int|null>  $itemIds
     * @return array<int, float>
     */
    public static function availableMany(iterable $itemIds): array
    {
        $ids = collect($itemIds)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Inventory::whereIn('item_id', $ids)->groupBy('item_id')
            ->selectRaw('item_id, SUM(actual_qty - reserved_qty) a')->pluck('a', 'item_id')
            ->map(fn ($a) => round(max(0.0, (float) $a), 2))->all();
    }

    /**
     * The stock itself (Master Produk) of several items: item id => actual qty — what is held for prepared
     * NPBG is still in it until their serah terima.
     *
     * @param  iterable<int|null>  $itemIds
     * @return array<int, float>
     */
    public static function onHandMany(iterable $itemIds): array
    {
        $ids = collect($itemIds)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Inventory::whereIn('item_id', $ids)->groupBy('item_id')
            ->selectRaw('item_id, SUM(actual_qty) a')->pluck('a', 'item_id')->map(fn ($a) => round(max(0.0, (float) $a), 2))->all();
    }

    /** Is what is left of this line already being bought (an open Draft PPB or a PPB)? */
    public static function inProcurement(ItemRequestLine $l): bool
    {
        // still in the Draft PPB list (not submitted, not taken off)
        if (DB::table('ppb_draft_lines as l')->join('ppb_drafts as d', 'd.id', '=', 'l.ppb_draft_id')
            ->where('l.item_request_line_id', $l->id)->whereNull('l.ppb_id')->where('d.status', 'DRAFT')->exists()) {
            return true;
        }

        if (! $l->ppb_id) {
            return false;
        }
        // a rejected PPB buys nothing
        $no = \App\Models\Ppb::whereKey($l->ppb_id)->value('no_ppb');

        return ! $no || (PpbApprovalService::statusFor(collect([$no]))[$no]['status'] ?? null) !== 'DITOLAK';
    }

    /** Prepared in an NPBG that waits for its serah terima (counts as given until handed over or not). */
    public static function pending(ItemRequestLine $l): float
    {
        return round((float) DB::table('item_request_fulfillment_lines as fl')->join('item_request_fulfillments as f', 'f.id', '=', 'fl.item_request_fulfillment_id')
            ->where('fl.item_request_line_id', $l->id)->whereIn('f.status', self::OPEN)->sum('fl.qty'), 2);
    }

    /** Still to give from stock: what is left minus what is already prepared. */
    public static function open(ItemRequestLine $l): float
    {
        return max(0.0, round($l->remaining() - self::pending($l), 2));
    }

    /**
     * What Gudang may give now, per line still owed.
     *
     * @return list<array{line_id:int, item_id:?int, kode_barang:?string, deskripsi:string, satuan:?string, approved:float, fulfilled:float, remaining:float, available:?float, suggested:float, in_procurement:bool}>
     */
    public function plan(ItemRequest $request): array
    {
        $out = [];
        foreach ($request->lines()->with('item:id,description')->get() as $l) {
            $rem = self::open($l);
            if ($rem <= 0) {
                continue;
            }
            $avail = self::available($l->item_id);
            $out[] = [
                'line_id' => $l->id, 'item_id' => $l->item_id, 'kode_barang' => $l->kode_barang,
                // the requester's name of the item; the Master Produk one beside it
                'deskripsi' => $l->requestedText(), 'item_description' => $l->item?->description, 'satuan' => $l->satuan,
                'approved' => (float) $l->approved_qty, 'fulfilled' => (float) $l->fulfilled_qty, 'remaining' => $rem,
                'available' => $avail, 'suggested' => $l->item_id && $avail !== null ? min($rem, $avail) : 0.0,
                'in_procurement' => self::inProcurement($l),
            ];
        }

        return $out;
    }

    /**
     * Right after the approval the SYSTEM decides per line from the real stock (not taken by other
     * requests): enough → NPBG of all of it; short → NPBG of what there is and the rest to a Draft PPB;
     * nothing (or a new item, not in the master) → Draft PPB. Later deliveries are Gudang's (prepare()).
     */
    public function allocate(ItemRequest $request, bool $shortageToPpb = true): ItemRequest
    {
        $lines = [];
        $owed = self::owed($request)->filter(fn ($l) => self::open($l) > 0)->values();
        $jasa = \App\Models\Item::whereIn('id', $owed->pluck('item_id')->filter())->where('product_kind', 'JASA')->pluck('id')->flip();
        foreach ($owed as $l) {
            // a service (Jasa) has no stock: it is always bought
            $avail = $l->item_id && ! $jasa->has($l->item_id) ? (self::available($l->item_id) ?? 0.0) : 0.0;
            $lines[] = ['line_id' => $l->id, 'qty' => round(min(self::open($l), max(0.0, $avail)), 2), 'shortage_to_ppb' => $shortageToPpb];
        }
        $acts = collect($lines)->contains(fn ($x) => $x['qty'] > 0)
            || ($shortageToPpb && collect($lines)->contains(fn ($x) => ! self::inProcurement($request->lines()->find($x['line_id']))));

        return $lines !== [] && $acts ? $this->prepare($request, null, $lines) : tap($request, fn ($r) => $this->recompute($r));
    }

    /**
     * Gudang gives what it can now.
     *
     * @param  list<array{line_id:int, qty:float, shortage_to_ppb?:bool}>  $lines
     */
    public function prepare(ItemRequest $request, ?User $user, array $lines): ItemRequest
    {
        $draft = null;
        DB::transaction(function () use (&$request, $user, $lines, &$draft) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ItemRequest::FULFILMENT_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Request ini belum bisa disiapkan ('.ItemRequestApprovalService::label($request->status).').']);
            }
            // an NPBG still waiting for its serah terima takes the new goods too: one serah terima for all of it
            $ready = $request->fulfillments()->whereIn('status', self::OPEN)->lockForUpdate()->first();
            $owned = $request->lines()->with('item:id,description')->get()->keyBy('id');
            $give = [];
            $shortage = [];
            foreach ($lines as $i => $in) {
                $l = $owned->get($in['line_id']) ?? throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Barang ini bukan bagian dari request.']);
                $qty = round((float) $in['qty'], 2);
                $rem = self::open($l);
                if ($qty < 0 || $qty > $rem + 0.0001) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => "Jumlah {$l->deskripsi_barang} paling banyak sisa kebutuhannya ({$this->num($rem)})."]);
                }
                if ($qty > 0) {
                    if (! $l->item_id) {
                        throw ValidationException::withMessages(["lines.{$i}.qty" => "{$l->deskripsi_barang} belum dicocokkan ke barang gudang, tidak bisa dikeluarkan dari stok."]);
                    }
                    if ($l->item?->product_kind === 'JASA' || \App\Models\Item::whereKey($l->item_id)->value('product_kind') === 'JASA') {
                        throw ValidationException::withMessages(["lines.{$i}.qty" => "{$l->deskripsi_barang} adalah Jasa, tidak ada stok, lewat PPB."]);
                    }
                    // lock the stock rows: two requests can't take the same last units
                    $avail = (float) Inventory::where('item_id', $l->item_id)->lockForUpdate()->get()->sum(fn ($r) => (float) $r->actual_qty - (float) $r->reserved_qty);
                    if ($qty > $avail + 0.0001) {
                        throw ValidationException::withMessages(["lines.{$i}.qty" => "Stok {$l->deskripsi_barang} hanya {$this->num(max(0, $avail))}, tidak cukup untuk {$this->num($qty)}."]);
                    }
                    $give[] = [$l, $qty];
                }
                $left = round($rem - $qty, 2);
                if ($left > 0 && ($in['shortage_to_ppb'] ?? false) && ! self::inProcurement($l)) {
                    $shortage[] = [$l, $left];
                }
            }
            if ($give === [] && $shortage === []) {
                throw ValidationException::withMessages(['lines' => 'Isi jumlah yang diberikan, atau teruskan kekurangannya ke Draft PPB.']);
            }

            if ($give !== []) {
                if ($ready) {
                    [$f, $seq, $noNpbg] = [$ready, $ready->seq, $ready->no_npbg];
                    // more goods joined it: Gudang fixes the quantities again before the serah terima
                    $ready->update(['status' => 'DISIAPKAN']);
                } else {
                    $seq = (int) $request->fulfillments()->max('seq') + 1;
                    $noNpbg = $this->numbers->next('NPBG', 'MANUAL');
                    $f = ItemRequestFulfillment::create([
                        'item_request_id' => $request->id, 'seq' => $seq, 'status' => 'DISIAPKAN', 'no_npbg' => $noNpbg,
                        'prepared_by' => $user?->id, 'prepared_at' => now(),
                    ]);
                }
                foreach ($give as [$l, $qty]) {
                    $npbg = Npbg::create([
                        'no_npbg' => $noNpbg, 'tgl_npbg' => now()->toDateString(),
                        'pelanggan' => $request->npbg_pelanggan ?: ($request->divisi ?: $request->requester_name),
                        'tipe_npbg' => $request->npbg_tipe ?: 'Non Penjualan',
                        'divisi' => $request->divisi, 'kode_barang' => $l->kode_barang,
                        'deskripsi_barang' => $l->item?->description ?? $l->deskripsi_barang,
                        'kuantitas' => $qty, 'satuan' => $l->satuan, 'peminta' => $request->requester_name,
                        'deskripsi' => mb_substr(trim("Request {$request->number}".($l->note ? ", {$l->note}" : '')), 0, 500),
                        'keterangan' => "Dari request {$request->number}".($seq > 1 ? " (NPBG lanjutan #{$seq})" : ''),
                        'unit_ratio' => 1, 'created_by' => $user?->id,
                    ]);
                    $wh = $this->reserve($l->item_id, $qty, $npbg, $user, "Dipesan NPBG {$noNpbg} · request {$request->number}");
                    $f->lines()->create(['item_request_line_id' => $l->id, 'qty' => $qty, 'npbg_id' => $npbg->id, 'warehouse_id' => $wh]);
                    if (! $l->npbg_id) {
                        $l->update(['npbg_id' => $npbg->id]);
                    }
                }
                $this->approvals->log($request, 'GUDANG', 'FULFILL', $user, "NPBG {$noNpbg} (#{$seq}): ".collect($give)
                    ->map(fn ($g) => "{$g[0]->deskripsi_barang} {$this->num($g[1])} {$g[0]->satuan}")->implode(', '));
            }

            if ($shortage !== []) {
                $draft = PpbDraft::create([
                    'number' => $this->numbers->nextShort('RST'), 'status' => 'DRAFT', 'source' => 'REQUEST',
                    'item_request_id' => $request->id, 'divisi' => $request->divisi ? mb_substr($request->divisi, 0, 30) : null,
                    'tgl_diperlukan' => $request->needed_date?->toDateString(), 'peminta' => $request->requester_name,
                    'keterangan' => "Kekurangan request {$request->number}", 'created_by' => $user?->id,
                ]);
                foreach ($shortage as [$l, $left]) {
                    $draft->lines()->create([
                        'item_id' => $l->item_id, 'item_request_line_id' => $l->id, 'kode_barang' => $l->kode_barang,
                        'deskripsi_barang' => mb_substr($l->item?->description ?? $l->deskripsi_barang, 0, 400),
                        'qty' => $left, 'satuan' => $l->satuan, 'basis' => "Kekurangan request {$request->number}",
                    ]);
                    $l->update(['ppb_draft_id' => $draft->id]);
                }
                $this->approvals->log($request, 'GUDANG', 'SHORTAGE', $user, "Kekurangan ke Draft PPB {$draft->number}: ".collect($shortage)
                    ->map(fn ($s) => "{$s[0]->deskripsi_barang} {$this->num($s[1])} {$s[0]->satuan}")->implode(', '));
            }
            $this->recompute($request);
        });

        if ($draft) {
            NotificationDispatcher::toPermission('ppb.create', 'ppb', 'info', "Draft PPB {$draft->number}",
                "Kekurangan stok untuk request {$request->number}, tinjau lalu ajukan jadi PPB.", "/ppb/drafts/{$draft->id}", $user?->id);
        }
        if ($request->status === 'DISIAPKAN' && $request->fulfillments()->where('status', 'DISIAPKAN')->exists()) {
            NotificationDispatcher::toPermission(ItemRequestApprovalService::HANDOVER_PERMISSION, 'request', 'info', "Siapkan barang: {$request->number}",
                'NPBG dibuat dari stok. Siapkan barangnya lalu lakukan serah terima.', "/item-requests/{$request->id}#siapkan");
        }

        return $request;
    }

    /**
     * Serah terima of one prepared NPBG.
     *
     * @param  array<int, float>  $handed  fulfillment line id => qty really handed over (default: all prepared)
     * @param  list<int>  $drop  fulfillment lines whose part not handed over is not needed any more: the approved
     *                           quantity drops by it (else it stays owed and is given again when stock is there)
     * @param  array<int, string>  $reasons  why less than the approved NPBG is handed over (required then: the stock was there)
     */
    public function handover(ItemRequest $request, ItemRequestFulfillment $f, User $user, string $photo, string $signature, string $receivedBy, ?string $note, array $handed = [], array $drop = [], array $reasons = []): ItemRequest
    {
        DB::transaction(function () use (&$request, $f, $user, $photo, $signature, $receivedBy, $note, $handed, $drop, $reasons) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            $f = ItemRequestFulfillment::lockForUpdate()->with('lines')->findOrFail($f->id);
            if ($f->item_request_id !== $request->id || ! in_array($f->status, self::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Tidak ada barang yang menunggu serah terima di sini.']);
            }
            // one step at Gudang: how much is really handed over is fixed in the serah terima itself
            $total = collect($f->lines)->sum(fn ($fl) => array_key_exists($fl->id, $handed) ? (float) $handed[$fl->id] : (float) $fl->qty);
            if ($total <= 0) {
                throw ValidationException::withMessages(['handed' => 'Isi jumlah barang yang diserahkan.']);
            }
            // the NPBG was made from stock that is there: handing over less needs a reason
            foreach ($f->lines as $fl) {
                $got = array_key_exists($fl->id, $handed) ? (float) $handed[$fl->id] : (float) $fl->qty;
                if ($got + 0.0001 < (float) $fl->qty && trim((string) ($reasons[$fl->id] ?? '')) === '') {
                    $name = ItemRequestLine::whereKey($fl->item_request_line_id)->value('deskripsi_barang') ?? 'barang';
                    throw ValidationException::withMessages(["reasons.{$fl->id}" => "Isi alasan jumlah {$name} dikurangi dari NPBG yang disetujui."]);
                }
            }
            $this->photos->putDataUrl(self::PHOTO_ENTITY, $f->id, 'handover', $photo, 'bukti-serah-terima', $user->id);
            $this->photos->putDataUrl(self::PHOTO_ENTITY, $f->id, 'signature', $signature, 'tanda-tangan', $user->id);
            $parts = [];
            foreach ($f->lines as $fl) {
                $got = array_key_exists($fl->id, $handed) ? round((float) $handed[$fl->id], 2) : (float) $fl->qty;
                if ($got < 0 || $got > $fl->qty + 0.0001) {
                    throw ValidationException::withMessages(["handed.{$fl->id}" => 'Jumlah diserahkan tidak boleh lebih dari yang disiapkan.']);
                }
                $back = round($fl->qty - $got, 2);
                $line = ItemRequestLine::find($fl->item_request_line_id);
                $part = ($line?->deskripsi_barang ?? '-').' '.$this->num($got).($line?->satuan ? " {$line->satuan}" : '');
                // the stock leaves now: what was held for this NPBG is released, what is handed over goes out
                if ($line?->item_id) {
                    $this->settle($fl->npbg_id, (int) $line->item_id, $got, $user, "Serah terima NPBG {$f->no_npbg} · request {$request->number}", $line->deskripsi_barang);
                }
                if ($back > 0) {
                    // less handed over than prepared: the rest stays in stock and the NPBG says what really left
                    if ($fl->npbg_id) {
                        // nothing of it left the warehouse: no NPBG line for it
                        $got > 0 ? Npbg::whereKey($fl->npbg_id)->update(['kuantitas' => $got]) : Npbg::whereKey($fl->npbg_id)->delete();
                    }
                    $closed = in_array($fl->id, $drop, true) && $line;
                    if ($closed) {
                        // not needed any more: the approved quantity drops by it (a PPB part of the line still waits)
                        $line->update(['approved_qty' => max((float) $line->fulfilled_qty + $got, round((float) $line->approved_qty - $back, 2))]);
                    }
                    $why = mb_substr(trim((string) ($reasons[$fl->id] ?? '')), 0, 200);
                    $part .= " (disetujui {$this->num($fl->qty)}, {$this->num($back)} tetap di stok".($closed ? ', sisa ditutup' : ', sisa tetap ditunggu')."; alasan: {$why})";
                }
                $fl->update(['handed_qty' => $got]);
                DB::table('item_request_lines')->where('id', $fl->item_request_line_id)->increment('fulfilled_qty', $got);
                $parts[] = $part;
            }
            $f->update(['status' => 'DISERAHKAN', 'handed_by' => $user->id, 'handed_at' => now(),
                'received_by_name' => mb_substr(trim($receivedBy), 0, 150), 'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null]);
            // the request keeps its latest serah terima too (older screens, Cek Permintaan)
            $request->update(['handed_over_at' => now(), 'handed_over_by' => $user->id, 'received_by_name' => $f->received_by_name, 'handover_note' => $f->note]);
            $this->approvals->log($request, 'SERAH_TERIMA', 'HANDOVER', $user, "NPBG {$f->no_npbg} (#{$f->seq}) diserahkan ke {$f->received_by_name}: ".implode('; ', $parts).($f->note ? ". Catatan: {$f->note}" : ''));
            $this->recompute($request);
        });
        NotificationDispatcher::toUser($request->requester_id, 'request', 'success',
            $request->status === 'SELESAI' ? "Request {$request->number} selesai" : "Request {$request->number}: sebagian sudah diserahkan",
            $request->status === 'SELESAI' ? 'Semua barang sudah diserahkan.' : 'Sisa barang masih diproses.', "/item-requests/{$request->id}");

        return $request;
    }

    /**
     * Gudang fixes how much of the prepared NPBG it really hands over (never more than prepared: that stock
     * was taken already); less goes back to the stock and the NPBG says what really leaves. Then it is
     * ready for the serah terima (SIAP). A line set to 0 leaves the NPBG.
     *
     * @param  array<int, float>  $quantities  fulfillment line id => qty (default: as prepared)
     */
    public function confirm(ItemRequest $request, ItemRequestFulfillment $f, User $user, array $quantities = []): ItemRequest
    {
        DB::transaction(function () use (&$request, $f, $user, $quantities) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            $f = ItemRequestFulfillment::lockForUpdate()->with('lines')->findOrFail($f->id);
            if ($f->item_request_id !== $request->id || $f->status !== 'DISIAPKAN') {
                throw ValidationException::withMessages(['status' => 'Tidak ada barang yang sedang disiapkan di sini.']);
            }
            $parts = [];
            foreach ($f->lines as $fl) {
                $qty = array_key_exists($fl->id, $quantities) ? round((float) $quantities[$fl->id], 2) : (float) $fl->qty;
                if ($qty < 0 || $qty > (float) $fl->qty + 0.0001) {
                    throw ValidationException::withMessages(["quantities.{$fl->id}" => 'Jumlah yang diserahkan 0–'.$this->num($fl->qty).' (yang sudah disiapkan dari stok).']);
                }
                $back = round((float) $fl->qty - $qty, 2);
                $line = ItemRequestLine::find($fl->item_request_line_id);
                if ($back > 0 && $line?->item_id) {
                    $this->release($fl->npbg_id, $back, $user, "Tidak jadi diserahkan · NPBG {$f->no_npbg}");
                }
                if ($qty <= 0) {
                    if ($fl->npbg_id) {
                        Npbg::whereKey($fl->npbg_id)->delete();
                    }
                    $fl->delete();
                } else {
                    if ($back > 0 && $fl->npbg_id) {
                        Npbg::whereKey($fl->npbg_id)->update(['kuantitas' => $qty]);
                    }
                    $fl->update(['qty' => $qty]);
                }
                $parts[] = ($line?->deskripsi_barang ?? '-').' '.$this->num($qty);
            }
            if (! $f->lines()->exists()) {
                $f->delete(); // nothing to hand over after all
            } else {
                $f->update(['status' => 'SIAP']);
            }
            $this->approvals->log($request, 'GUDANG', 'CONFIRM', $user, "Jumlah diserahkan NPBG {$f->no_npbg}: ".implode(', ', $parts));
            $this->recompute($request);
        });
        if ($request->status === 'SIAP_DISERAHKAN') {
            NotificationDispatcher::toUser($request->requester_id, 'request', 'success', "Request {$request->number}: barang siap diambil",
                'Silakan ambil di gudang dan tanda tangan saat serah terima.', "/item-requests/{$request->id}");
        }

        return $request;
    }

    /** Close what is left of some lines (or all), with a reason — e.g. not needed any more. */
    public function close(ItemRequest $request, User $user, ?array $lineIds, string $reason): ItemRequest
    {
        DB::transaction(function () use (&$request, $user, $lineIds, $reason) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ItemRequest::FULFILMENT_STATUSES, true)) {
                throw ValidationException::withMessages(['status' => 'Sisa kebutuhan hanya bisa ditutup setelah request disetujui.']);
            }
            $lines = $request->lines()->get()->filter(fn ($l) => $l->remaining() > 0 && ($lineIds === null || in_array($l->id, $lineIds, true)));
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Tidak ada sisa kebutuhan yang bisa ditutup.']);
            }
            foreach ($lines as $l) {
                $l->update(['closed_at' => now(), 'closed_by' => $user->id, 'closed_reason' => mb_substr(trim($reason), 0, 255)]);
                // not needed any more: out of its Draft PPB that is not submitted yet (a draft left empty is cancelled)
                $draft = $l->ppb_draft_id ? PpbDraft::whereKey($l->ppb_draft_id)->where('status', 'DRAFT')->first() : null;
                if ($draft) {
                    $draft->lines()->where('item_request_line_id', $l->id)->whereNull('ppb_id')->delete();
                    if (! $draft->lines()->exists()) {
                        $draft->update(['status' => 'DIBATALKAN']);
                    }
                }
            }
            $this->approvals->log($request, 'GUDANG', 'CLOSE', $user, 'Sisa ditutup: '.$lines->map(fn ($l) => "{$l->deskripsi_barang} {$this->num($l->approved_qty - $l->fulfilled_qty)}")->implode(', ').", {$reason}");
            $this->recompute($request);
        });

        return $request;
    }

    /**
     * The request status from its lines (only once it is past the approvals):
     *   nothing left                                    → SELESAI
     *   an NPBG prepared, not yet handed over           → SIAP_DISERAHKAN
     *   something left that the stock can give now      → DISIAPKAN (Gudang acts)
     *   the rest is being bought: PPB has a PO / not yet → MENUNGGU_BARANG / MENUNGGU_PENGADAAN
     *   some already handed over, nothing else to do    → DIPENUHI_SEBAGIAN
     */
    public function recompute(ItemRequest $request): string
    {
        $lines = $request->lines()->get();
        $left = $lines->filter(fn (ItemRequestLine $l) => $l->remaining() > 0);
        $status = match (true) {
            // an NPBG Gudang is still fixing → DISIAPKAN; one ready → SIAP_DISERAHKAN
            $request->fulfillments()->where('status', 'DISIAPKAN')->exists() => 'DISIAPKAN',
            $request->fulfillments()->where('status', 'SIAP')->exists() => 'SIAP_DISERAHKAN',
            $left->isEmpty() => $lines->contains(fn ($l) => in_array($l->decision, ['NPBG', 'PPB'], true)) ? 'SELESAI' : 'DITOLAK',
            $left->contains(fn ($l) => $l->item_id && (self::available($l->item_id) ?? 0) > 0) => 'DISIAPKAN',
            $left->contains(fn ($l) => self::ordered($l)) => 'MENUNGGU_BARANG',
            $left->contains(fn ($l) => self::inProcurement($l)) => 'MENUNGGU_PENGADAAN',
            $lines->sum('fulfilled_qty') > 0 => 'DIPENUHI_SEBAGIAN',
            default => 'DISIAPKAN',
        };
        if ($status !== $request->status) {
            $request->update(['status' => $status]);
        }

        return $status;
    }

    /**
     * Requests waiting for goods: once stock is there again (an RI, the Accurate sync), back to
     * DISIAPKAN and Gudang is told — never an automatic NPBG (Gudang decides the quantity).
     */
    public function refreshWaiting(): int
    {
        $woke = 0;
        // oldest request first: it gets the goods that just arrived
        foreach (ItemRequest::whereIn('status', ItemRequest::FULFILMENT_STATUSES)->orderBy('id')->get() as $r) {
            $lines = fn () => DB::table('item_request_fulfillment_lines as fl')->join('item_request_fulfillments as f', 'f.id', '=', 'fl.item_request_fulfillment_id')
                ->where('f.item_request_id', $r->id)->count();
            $before = $lines();
            if ($this->giveArrived($r) && $lines() > $before) {
                $woke++;
                NotificationDispatcher::toPermission(ItemRequestApprovalService::HANDOVER_PERMISSION, 'request', 'success', "Barang datang: {$r->number}",
                    "Barang yang ditunggu {$r->requester_name} sudah masuk stok dan disiapkan, lakukan serah terima.", "/item-requests/{$r->id}");
            } else {
                $this->recompute($r);
            }
        }

        return $woke;
    }

    /**
     * Goods that arrived (RI, sync) for a request still owed: given at once from the stock — added to the NPBG
     * waiting for its serah terima, or a new NPBG lanjutan — so Gudang can hand them over. What is still short
     * keeps waiting for purchasing (no new Draft PPB from here).
     */
    private function giveArrived(ItemRequest $r): bool
    {
        $any = self::owed($r)->contains(fn ($l) => self::open($l) > 0 && $l->item_id && (self::available($l->item_id) ?? 0) > 0
            && \App\Models\Item::whereKey($l->item_id)->value('product_kind') !== 'JASA');
        if (! $any) {
            return false;
        }
        try {
            $this->allocate($r, false);

            return true;
        } catch (ValidationException $e) {
            report($e);

            return false;
        }
    }

    /**
     * Hold qty of the item's free stock for an NPBG until its serah terima (most free stock first): the stock
     * itself (Master Produk) stays as it is, other requests cannot take it. Returns the first warehouse used.
     */
    private function reserve(int $itemId, float $qty, Npbg $npbg, ?User $user, string $note): ?int
    {
        $first = null;
        $left = $qty;
        foreach (Inventory::where('item_id', $itemId)->orderByRaw('(actual_qty - reserved_qty) DESC')->get() as $inv) {
            $take = round(min($left, (float) $inv->actual_qty - (float) $inv->reserved_qty), 2);
            if ($take <= 0) {
                continue;
            }
            $this->ledger->record(['type' => 'RESERVATION', 'item_id' => $itemId, 'warehouse_id' => $inv->warehouse_id, 'qty' => $take, 'reserve_delta' => $take,
                'reference' => $npbg, 'note' => $note, 'created_by' => $user?->id]);
            $first ??= $inv->warehouse_id;
            $left = round($left - $take, 2);
            if ($left <= 0) {
                break;
            }
        }

        return $first;
    }

    /** warehouse id => qty still held for this NPBG */
    public static function held(?int $npbgId): array
    {
        if (! $npbgId) {
            return [];
        }

        return DB::table('stock_movements')->where('reference_type', (new Npbg)->getMorphClass())->where('reference_id', $npbgId)
            ->whereIn('movement_type', ['RESERVATION', 'RELEASE_RESERVATION'])->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(reserved_after - reserved_before) q')->pluck('q', 'warehouse_id')
            ->map(fn ($q) => round((float) $q, 2))->filter(fn ($q) => $q > 0)->all();
    }

    /** Release up to qty of what is held for an NPBG (all of it when null). */
    private function release(?int $npbgId, ?float $qty, ?User $user, string $note): void
    {
        $left = $qty;
        foreach (self::held($npbgId) as $wh => $q) {
            $inv = Inventory::where(['item_id' => $this->heldItem($npbgId), 'warehouse_id' => $wh])->first();
            // never more than the warehouse still holds (an Accurate sync may have lowered it)
            $rel = round(min($q, $left ?? $q, (float) ($inv?->reserved_qty ?? 0)), 2);
            if ($rel > 0) {
                $this->ledger->record(['type' => 'RELEASE_RESERVATION', 'item_id' => $inv->item_id, 'warehouse_id' => $wh, 'qty' => $rel, 'reserve_delta' => -$rel,
                    'reference' => Npbg::withoutGlobalScopes()->find($npbgId) ?? (new Npbg)->forceFill(['id' => $npbgId]), 'note' => $note, 'created_by' => $user?->id]);
            }
            if ($left !== null) {
                $left = round($left - $q, 2);
                if ($left <= 0) {
                    break;
                }
            }
        }
    }

    private function heldItem(?int $npbgId): ?int
    {
        return $npbgId ? DB::table('stock_movements')->where('reference_type', (new Npbg)->getMorphClass())->where('reference_id', $npbgId)
            ->where('movement_type', 'RESERVATION')->value('item_id') : null;
    }

    /**
     * The serah terima: what was held for the NPBG is released, and qty leaves the stock
     * (from the warehouses that held it first).
     */
    private function settle(?int $npbgId, int $itemId, float $qty, User $user, string $note, ?string $name): void
    {
        $held = self::held($npbgId);
        $this->release($npbgId, null, $user, $note);
        $left = round($qty, 2);
        if ($left <= 0) {
            return;
        }
        $rows = Inventory::where('item_id', $itemId)->get()
            ->sortByDesc(fn ($inv) => [isset($held[$inv->warehouse_id]) ? 1 : 0, (float) $inv->actual_qty - (float) $inv->reserved_qty]);
        $npbg = $npbgId ? Npbg::find($npbgId) : null;
        foreach ($rows as $inv) {
            $take = round(min($left, (float) $inv->actual_qty - (float) $inv->reserved_qty), 2);
            if ($take <= 0) {
                continue;
            }
            $this->ledger->record(['type' => 'STOCK_OUT', 'item_id' => $itemId, 'warehouse_id' => $inv->warehouse_id, 'qty' => $take,
                'reference' => $npbg, 'note' => $note, 'created_by' => $user->id]);
            $left = round($left - $take, 2);
            if ($left <= 0) {
                return;
            }
        }
        throw ValidationException::withMessages(['handed' => 'Stok '.($name ?? 'barang')." tidak cukup untuk diserahkan (kurang {$this->num($left)})."]);
    }

    private function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    }

    /**
     * One line's own status (the request's status is the sum of them):
     * MENUNGGU (not decided) / DITOLAK / the approval step / SIAP_DISERAHKAN / SELESAI / DITUTUP /
     * DISIAPKAN (stock there) / MENUNGGU_BARANG / MENUNGGU_PENGADAAN / DIPENUHI_SEBAGIAN.
     *
     * @param  list<int>  $preparedLineIds  lines in a prepared, not yet handed over NPBG
     */
    public static function lineStatus(ItemRequestLine $l, ItemRequest $request, array $preparedLineIds = [], bool $confirmed = true): string
    {
        if ($l->decision === null) {
            return $request->status === 'PERLU_PERBAIKAN' ? 'PERLU_PERBAIKAN' : 'MENUNGGU';
        }
        if ($l->decision === 'TOLAK' || $request->status === 'DITOLAK') {
            return 'DITOLAK';
        }
        if (in_array($request->status, ['APPROVAL_MANAGER', 'APPROVAL_DIREKTUR', 'DIPROSES', 'DIBATALKAN'], true)) {
            return $request->status;
        }
        if (in_array($l->id, $preparedLineIds, true)) {
            return $confirmed ? 'SIAP_DISERAHKAN' : 'DISIAPKAN';
        }
        if ($l->remaining() <= 0) {
            return $l->closed_at && (float) $l->fulfilled_qty + 0.0001 < (float) $l->approved_qty ? 'DITUTUP' : 'SELESAI';
        }
        if ($l->item_id && (self::available($l->item_id) ?? 0) > 0) {
            return 'DISIAPKAN';
        }
        if (self::ordered($l)) {
            return 'MENUNGGU_BARANG';
        }
        if (self::inProcurement($l)) {
            return 'MENUNGGU_PENGADAAN';
        }

        return (float) $l->fulfilled_qty > 0 ? 'DIPENUHI_SEBAGIAN' : 'DISIAPKAN';
    }

    /**
     * A clearer label once the request is being fulfilled: how much came from stock (NPBG, partial or not)
     * and what the rest waits for — e.g. "NPBG partial & menunggu pengadaan",
     * "Siap serah terima · NPBG partial & menunggu barang (PO)", "Menunggu pengadaan (PPB)".
     */
    public static function statusLabel(ItemRequest $request): string
    {
        if (! in_array($request->status, ItemRequest::FULFILMENT_STATUSES, true)) {
            return ItemRequestApprovalService::label($request->status);
        }
        $lines = $request->lines()->get()->filter(fn ($l) => in_array($l->decision, ['NPBG', 'PPB'], true));

        $preparing = $request->status === 'DISIAPKAN' && $request->fulfillments()->where('status', 'DISIAPKAN')->exists();

        return self::describe($lines, $request->status === 'SIAP_DISERAHKAN', $request->status, $preparing);
    }

    /** The same for one line (the Status column of the Item table). */
    public static function lineLabel(ItemRequestLine $l, ItemRequest $request, string $code): string
    {
        if (! in_array($code, ['SIAP_DISERAHKAN', 'DISIAPKAN', 'DIPENUHI_SEBAGIAN', 'MENUNGGU_PENGADAAN', 'MENUNGGU_BARANG'], true)) {
            return ItemRequestApprovalService::label($code);
        }

        return self::describe(collect([$l]), $code === 'SIAP_DISERAHKAN', $code, $code === 'DISIAPKAN' && self::pending($l) > 0);
    }

    /** @param  \Illuminate\Support\Collection<int, ItemRequestLine>  $lines */
    private static function describe(Collection $lines, bool $ready, string $code, bool $preparing = false): string
    {
        $given = $lines->sum(fn ($l) => (float) $l->fulfilled_qty + self::pending($l));
        $open = $lines->filter(fn ($l) => self::open($l) > 0);
        $ordered = $open->contains(fn ($l) => self::ordered($l));
        $buying = $open->contains(fn ($l) => self::inProcurement($l));
        $wait = $ordered ? 'menunggu barang (PO)' : ($buying ? 'menunggu pengadaan' : null);

        $parts = [];
        if ($given > 0 && $open->isNotEmpty()) {
            $parts[] = 'NPBG partial'.($wait ? " & {$wait}" : '');
        } elseif ($given <= 0 && $wait) {
            $parts[] = ucfirst($wait).($ordered ? '' : ' (PPB)');
        }
        if ($ready) {
            array_unshift($parts, 'Siap serah terima');
        } elseif ($preparing) {
            array_unshift($parts, 'Siapkan & serah terima gudang');
        }
        if ($parts === []) {
            return ItemRequestApprovalService::label($code);
        }

        return implode(' · ', $parts);
    }

    /** Its PPB has a PO approved / sent to the vendor: the goods are on their way. */
    public static function ordered(ItemRequestLine $l): bool
    {
        return (bool) $l->ppb_id && DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->where('i.approved_ppb_id', $l->ppb_id)->whereIn('o.status', ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED'])->exists();
    }

    /** @return Collection<int, ItemRequestLine> */
    public static function owed(ItemRequest $request): Collection
    {
        return $request->lines()->get()->filter(fn (ItemRequestLine $l) => $l->remaining() > 0)->values();
    }
}
