<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\Ppb;
use App\Models\PpbDraft;
use App\Models\PpbDraftLine;
use App\Models\User;
use App\Services\Inventory\InventoryAnalyzer;
use App\Support\PpbLineStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restock → Draft PPB → PPB.
 *   restock()  the items picked on Safety Stock become one DRAFT, each line with the analysis'
 *              recommended quantity and why ("Tersedia 3 · Safety stock 10 · Saran 12")
 *   update()   while DRAFT the admin edits the header and the lines (qty, add, remove)
 *   submit()   the draft becomes a real manual PPB (same as "Tambah PPB": one row per line,
 *              then PPB approval before PO) and is closed as DIAJUKAN
 */
class PpbDraftService
{
    public function __construct(private DocumentNumberService $numbers, private InventoryAnalyzer $analyzer) {}

    /** items Restock left out (already in an open draft / already ordered enough), for its answer */
    public array $skipped = [];

    /**
     * Restock: what is already on its way (OnOrder) is taken off the suggestion; an item already in an
     * open Draft PPB, or ordered enough, is left out (no double buying).
     *
     * @param  list<array{item_id:int, qty?:float|null}>  $picked
     */
    public function restock(User $user, array $picked): PpbDraft
    {
        $this->skipped = [];
        $ids = collect($picked)->pluck('item_id')->unique()->values();
        $items = Item::with('unit:id,code')->whereIn('id', $ids)->get()->keyBy('id');
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu barang.']);
        }
        if ($items->contains(fn (Item $i) => $i->product_kind === 'JASA')) {
            throw ValidationException::withMessages(['items' => 'Jasa tidak punya stok — tidak bisa di-restock.']);
        }
        $analysis = $this->analyzer->compute()['rows']->keyBy('item_id');
        $onOrder = \App\Services\Inventory\OnOrder::byItem($ids->all());

        $lines = [];
        foreach (collect($picked)->unique('item_id') as $p) {
            $item = $items->get($p['item_id']);
            if (! $item) {
                continue;
            }
            $o = $onOrder[$item->id] ?? null;
            if ($o && $o['draft'] > 0) {
                $this->skipped[] = ['item_id' => $item->id, 'code' => $item->code, 'reason' => 'sudah ada di '.implode(', ', array_filter($o['docs'], fn ($d) => str_starts_with($d, 'Draft')))];

                continue;
            }
            $a = $analysis->get($item->id);
            $base = (float) ($a->recommended_qty ?? 0) > 0 ? (float) $a->recommended_qty : max(1.0, (float) ($a->deficit ?? 0));
            $ordered = (float) ($o['total'] ?? 0);
            $suggested = round(max(0.0, $base - $ordered), 2);
            $given = isset($p['qty']) && (float) $p['qty'] > 0 ? (float) $p['qty'] : null;
            if ($given === null && $suggested <= 0) {
                $this->skipped[] = ['item_id' => $item->id, 'code' => $item->code, 'reason' => 'sudah dipesan cukup ('.implode(', ', $o['docs']).')'];

                continue;
            }
            $lines[] = [
                'item_id' => $item->id, 'kode_barang' => $item->code, 'deskripsi_barang' => $item->description,
                'qty' => round($given ?? $suggested, 2), 'satuan' => $item->unit?->code,
                'basis' => $a ? sprintf('Tersedia %s · Safety stock %s · Saran %s', $this->num($a->available), $this->num($a->safety_stock), $this->num($base))
                    .($ordered > 0 ? sprintf(' · Dalam pesanan %s (%s) → %s', $this->num($ordered), implode(', ', $o['docs']), $this->num($suggested)) : '') : null,
            ];
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Tidak ada yang perlu di-restock: '.collect($this->skipped)->map(fn ($x) => "{$x['code']} {$x['reason']}")->implode('; ').'.']);
        }

        return DB::transaction(function () use ($user, $lines) {
            $draft = PpbDraft::create(['number' => $this->numbers->nextShort('RST'), 'status' => 'DRAFT', 'source' => 'RESTOCK', 'created_by' => $user->id]);
            foreach ($lines as $l) {
                $draft->lines()->create($l);
            }

            return $draft;
        });
    }

    /**
     * @param  array{divisi?:?string, tgl_diperlukan?:?string, peminta?:?string, keterangan?:?string,
     *               lines?: list<array{id?:?int, item_id?:?int, kode_barang?:?string, deskripsi_barang:string, qty:float, satuan?:?string, catatan?:?string}>}  $data
     */
    public function update(PpbDraft $draft, array $data): PpbDraft
    {
        return DB::transaction(function () use ($draft, $data) {
            $draft = PpbDraft::lockForUpdate()->findOrFail($draft->id);
            $this->expectDraft($draft);
            $draft->update(array_intersect_key($data, array_flip(['divisi', 'tgl_diperlukan', 'peminta', 'keterangan'])));
            if (array_key_exists('lines', $data)) {
                if (count($data['lines']) === 0) {
                    throw ValidationException::withMessages(['lines' => 'Draft harus berisi minimal satu barang.']);
                }
                $keep = [];
                foreach ($data['lines'] as $l) {
                    $item = ! empty($l['item_id']) ? Item::with('unit:id,code')->find($l['item_id']) : null;
                    $values = [
                        'item_id' => $item?->id, 'kode_barang' => $item?->code ?? ($l['kode_barang'] ?? null),
                        'deskripsi_barang' => $item?->description ?? $l['deskripsi_barang'],
                        'qty' => round((float) $l['qty'], 2), 'satuan' => ($l['satuan'] ?? null) ?: $item?->unit?->code,
                        'catatan' => $l['catatan'] ?? null,
                    ];
                    $line = ! empty($l['id']) ? $draft->lines()->whereNull('ppb_id')->find($l['id']) : null;
                    $line ? $line->update($values) : $line = $draft->lines()->create($values);
                    $keep[] = $line->id;
                }
                $draft->lines()->whereNull('ppb_id')->whereNotIn('id', $keep)->delete(); // lines already in a PPB stay
            }

            return $draft->refresh();
        });
    }

    /**
     * The Draft PPB list: the picked lines (from any Request Barang / Safety Stock draft) become ONE PPB.
     * A draft whose lines are all submitted is closed (DIAJUKAN).
     *
     * @param  list<int>  $lineIds
     * @param  array{no_ppb:string, tgl_ppb:string, divisi?:?string, tgl_diperlukan?:?string, keterangan?:?string}  $data
     * @return list<int> the PPB rows made
     */
    public function submitLines(User $user, array $lineIds, array $data): array
    {
        $made = [];
        DB::transaction(function () use ($user, $lineIds, $data, &$made) {
            $lines = PpbDraftLine::with('draft.itemRequest:id,number,requester_name,needed_date,divisi')->whereIn('id', $lineIds)->lockForUpdate()->orderBy('id')->get();
            if ($lines->isEmpty() || $lines->count() !== count(array_unique($lineIds))) {
                throw ValidationException::withMessages(['line_ids' => 'Pilih barang yang masih di Draft PPB.']);
            }
            if ($lines->contains(fn ($l) => $l->ppb_id !== null || $l->draft->status !== 'DRAFT')) {
                throw ValidationException::withMessages(['line_ids' => 'Sebagian barang sudah diajukan / dibatalkan — muat ulang daftar.']);
            }
            $noPpb = mb_substr(trim($data['no_ppb']), 0, 60);
            foreach ($lines as $l) {
                $d = $l->draft;
                $source = $d->itemRequest ? "Request {$d->itemRequest->number}" : "Safety stock ({$d->number})";
                $row = Ppb::create([
                    'no_ppb' => $noPpb, 'tgl_ppb' => $data['tgl_ppb'],
                    'tgl_diperlukan' => ($data['tgl_diperlukan'] ?? null) ?: ($d->tgl_diperlukan?->toDateString()),
                    'divisi' => mb_substr((string) (($data['divisi'] ?? null) ?: $d->divisi ?: ''), 0, 30) ?: null,
                    'kode_barang' => $l->kode_barang, 'deskripsi_barang' => $l->deskripsi_barang,
                    'kuantitas' => $l->qty, 'satuan' => $l->satuan, 'peminta' => $d->peminta ?: $user->name,
                    'catatan_baris' => mb_substr(trim($source.($l->catatan ? " · {$l->catatan}" : '')), 0, 255),
                    'keterangan' => ($data['keterangan'] ?? null) ?: 'Dari Draft PPB',
                    'status' => 'OPEN', 'line_status' => PpbLineStatus::of($l->qty, null, false), 'is_amend' => false,
                    'created_by' => $user->id,
                ]);
                $made[] = $row->id;
                $l->update(['ppb_id' => $row->id, 'submitted_at' => now()]);
                if ($l->item_request_line_id) {
                    DB::table('item_request_lines')->where('id', $l->item_request_line_id)->update(['ppb_id' => $row->id, 'updated_at' => now()]);
                }
            }
            foreach ($lines->pluck('draft')->unique('id') as $d) {
                if (! $d->lines()->whereNull('ppb_id')->exists()) {
                    $d->update(['status' => 'DIAJUKAN', 'no_ppb' => $noPpb, 'submitted_at' => now(), 'submitted_by' => $user->id]);
                }
            }
        });
        app(PpbApprovalService::class)->scan(); // the new PPB waits for approval before PO

        return $made;
    }

    /** Change one line of the list (quantity / note) while it is not submitted. */
    public function updateLine(PpbDraftLine $line, array $data): PpbDraftLine
    {
        $this->expectOpenLine($line);
        $line->update(array_intersect_key($data, array_flip(['qty', 'catatan'])));

        return $line;
    }

    /** Take a line off the list (not bought); its draft left empty is cancelled, its Request Barang moves on. */
    public function removeLine(PpbDraftLine $line): void
    {
        $this->expectOpenLine($line);
        $draft = $line->draft;
        DB::transaction(function () use ($line, $draft) {
            $line->delete();
            if (! $draft->lines()->exists()) {
                $draft->update(['status' => 'DIBATALKAN']);
            } elseif (! $draft->lines()->whereNull('ppb_id')->exists()) {
                $draft->update(['status' => 'DIAJUKAN']);
            }
        });
        $req = $draft->item_request_id ? ItemRequest::find($draft->item_request_id) : null;
        if ($req && in_array($req->status, ItemRequest::FULFILMENT_STATUSES, true)) {
            app(ItemRequestFulfillmentService::class)->recompute($req);
        }
    }

    private function expectOpenLine(PpbDraftLine $line): void
    {
        if ($line->ppb_id !== null || $line->draft->status !== 'DRAFT') {
            throw ValidationException::withMessages(['line' => 'Barang ini sudah diajukan jadi PPB / dibatalkan.']);
        }
    }

    /** @param  array{no_ppb:string, tgl_ppb:string, divisi?:?string, tgl_diperlukan?:?string, peminta?:?string, keterangan?:?string}  $data */
    public function submit(PpbDraft $draft, User $user, array $data): PpbDraft
    {
        DB::transaction(function () use (&$draft, $user, $data) {
            $draft = PpbDraft::lockForUpdate()->findOrFail($draft->id);
            $this->expectDraft($draft);
            $lines = $draft->lines()->whereNull('ppb_id')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Draft kosong.']);
            }
            $noPpb = mb_substr(trim($data['no_ppb']), 0, 60);
            $header = [
                'divisi' => ($data['divisi'] ?? $draft->divisi) ?: null,
                'tgl_diperlukan' => ($data['tgl_diperlukan'] ?? $draft->tgl_diperlukan?->toDateString()) ?: null,
                'peminta' => ($data['peminta'] ?? $draft->peminta) ?: null,
                'keterangan' => ($data['keterangan'] ?? $draft->keterangan) ?: "Restock dari {$draft->number}",
            ];
            foreach ($lines as $l) {
                $made = Ppb::create([
                    'no_ppb' => $noPpb, 'tgl_ppb' => $data['tgl_ppb'],
                    'tgl_diperlukan' => $header['tgl_diperlukan'], 'divisi' => $header['divisi'] ? mb_substr($header['divisi'], 0, 30) : null,
                    'kode_barang' => $l->kode_barang, 'deskripsi_barang' => $l->deskripsi_barang,
                    'kuantitas' => $l->qty, 'satuan' => $l->satuan, 'peminta' => $header['peminta'],
                    'catatan_baris' => $l->catatan, 'keterangan' => $header['keterangan'],
                    'status' => 'OPEN', 'line_status' => PpbLineStatus::of($l->qty, null, false), 'is_amend' => false,
                    'created_by' => $user->id,
                ]);
                $l->update(['ppb_id' => $made->id, 'submitted_at' => now()]);
                // a line from a Request Barang now knows its PPB
                if ($l->item_request_line_id) {
                    DB::table('item_request_lines')->where('id', $l->item_request_line_id)->update(['ppb_id' => $made->id, 'updated_at' => now()]);
                }
            }
            $draft->update($header + ['status' => 'DIAJUKAN', 'no_ppb' => $noPpb, 'submitted_at' => now(), 'submitted_by' => $user->id]);
        });
        app(PpbApprovalService::class)->scan(); // the new PPB waits for approval before PO

        return $draft;
    }

    public function cancel(PpbDraft $draft): PpbDraft
    {
        $this->expectDraft($draft);
        $draft->update(['status' => 'DIBATALKAN']);
        // its Request Barang lines are no longer being bought: back to Gudang / partly fulfilled
        $req = $draft->item_request_id ? ItemRequest::find($draft->item_request_id) : null;
        if ($req && in_array($req->status, ItemRequest::FULFILMENT_STATUSES, true)) {
            app(ItemRequestFulfillmentService::class)->recompute($req);
        }

        return $draft;
    }

    private function expectDraft(PpbDraft $draft): void
    {
        if ($draft->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => $draft->status === 'DIAJUKAN'
                ? "Draft ini sudah diajukan jadi PPB {$draft->no_ppb}." : 'Draft ini sudah dibatalkan.']);
        }
    }

    private function num(mixed $v): string
    {
        return $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    }
}
