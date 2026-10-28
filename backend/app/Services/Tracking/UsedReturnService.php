<?php

namespace App\Services\Tracking;

use App\Models\Npbg;
use App\Models\UsedReturn;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\ReceivingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengembalian Bekas — sisa/bekas/rusak dari pemakaian dikembalikan. docs/status-flow.md §8.
 * PENDING -> CLEAR (bekas dicatat lewat RI CONFIRMED).
 * item into_stock=true & condition REUSABLE/USED -> movement RETURN saat RI CONFIRMED (di ReceivingService).
 * qty negatif (shortage) -> hanya catatan, tidak gerakkan stok.
 *
 * close() used to just flip status to CLEAR with whatever ri_id the caller
 * happened to pass — but nothing ever passed one (no UI for it, same gap as
 * Lend/Borrow/STPP/TyreChange's own return_ri_id/return actions, and there's
 * no screen anywhere to create a non-purchase Receiving to link to by hand).
 * So "closing" never actually produced a Receiving or a stock movement,
 * despite the docs describing exactly that. Fixed by having close() create
 * the Receiving (source_type USED_RETURN) straight from this record's own
 * items and confirm it in the same step — the line data was already entered
 * and reviewed when the Pengembalian Bekas itself was created/edited.
 */
class UsedReturnService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ReceivingService $receiving,
    ) {}

    /**
     * @param  array{npbg_id?:?int, npbg_ref_raw?:?string, return_date?:?string, format?:string, site_id?:?int, note?:?string, items:array<int,array{item_id?:?int, component_type_id?:?int, description_raw?:string, qty:float, unit_id?:?int, condition?:string, into_stock?:bool}>}  $data
     */
    public function create(User $user, array $data): UsedReturn
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => ['Minimal 1 baris.']]);
        }
        $date = Carbon::parse($data['return_date'] ?? now());

        return DB::transaction(function () use ($user, $data, $date) {
            $ur = UsedReturn::create([
                'number' => $this->numbers->next('UR', 'SDA', $date),
                'npbg_id' => $data['npbg_id'] ?? $this->resolveNpbgId($data['npbg_ref_raw'] ?? null),
                'npbg_ref_raw' => $data['npbg_ref_raw'] ?? null,
                'return_date' => $date->toDateString(),
                'status' => 'PENDING',
                'format' => $data['format'] ?? 'ITEM_LINE',
                'site_id' => $data['site_id'] ?? $user->site_id,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach (array_values($data['items']) as $i => $row) {
                $ur->items()->create([
                    'item_id' => $row['item_id'] ?? null,
                    'component_type_id' => $row['component_type_id'] ?? null,
                    'description_raw' => $row['description_raw'] ?? null,
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'] ?? null,
                    'condition' => strtoupper($row['condition'] ?? 'USED'),
                    'into_stock' => $row['into_stock'] ?? false,
                    'item_no' => $i + 1,
                ]);
            }

            return $ur->load('items');
        });
    }

    /**
     * @param  array{warehouse_id:int, return_date?:?string}  $data
     */
    public function close(User $user, UsedReturn $ur, array $data): UsedReturn
    {
        if ($ur->status === 'CLEAR') {
            throw ValidationException::withMessages(['status' => ['Pengembalian bekas sudah CLEAR.']]);
        }
        $ur->load('items');
        if ($ur->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Tidak ada baris barang untuk diproses.']]);
        }

        return DB::transaction(function () use ($user, $ur, $data) {
            $ri = $this->receiving->create($user, [
                'warehouse_id' => $data['warehouse_id'],
                'source_type' => 'USED_RETURN',
                'lines' => $ur->items->map(fn ($i) => [
                    'item_id' => $i->item_id,
                    'description_raw' => $i->description_raw ?? $i->componentType?->name ?? 'Barang bekas',
                    'qty_received' => (float) $i->qty,
                    'qty_accepted' => (float) $i->qty,
                    'unit_id' => $i->unit_id,
                    // condition SCRAP/DAMAGED lines are already saved with into_stock=false
                    // by the create/edit form (docs §8) — carried through as-is, not re-derived
                    // here, so a reviewer's per-line judgment call isn't silently overridden.
                    'into_stock' => (bool) $i->into_stock,
                ])->all(),
            ]);

            $ri = $this->receiving->confirm($ri, $user);

            $ur->update([
                'status' => 'CLEAR',
                'ri_id' => $ri->id,
                'return_date' => $data['return_date'] ?? $ur->return_date?->toDateString() ?? now()->toDateString(),
            ]);

            return $ur->refresh()->load('items');
        });
    }

    /**
     * Ubah data Pengembalian Bekas selagi PENDING (belum ada RI bekas).
     *
     * @param  array{npbg_ref_raw?:?string, return_date?:?string, note?:?string, items?:array<int,array{item_id?:?int, component_type_id?:?int, description_raw?:string, qty:float, unit_id?:?int, condition?:string, into_stock?:bool}>}  $data
     */
    public function update(UsedReturn $ur, array $data): UsedReturn
    {
        $this->assertEditable($ur);

        return DB::transaction(function () use ($ur, $data) {
            $npbgRef = $data['npbg_ref_raw'] ?? $ur->npbg_ref_raw;
            $ur->update([
                'npbg_ref_raw' => $npbgRef,
                'npbg_id' => $npbgRef === $ur->npbg_ref_raw ? $ur->npbg_id : $this->resolveNpbgId($npbgRef),
                'return_date' => isset($data['return_date']) ? Carbon::parse($data['return_date'])->toDateString() : $ur->return_date?->toDateString(),
                'note' => $data['note'] ?? $ur->note,
            ]);

            if (isset($data['items'])) {
                $ur->items()->delete();
                foreach (array_values($data['items']) as $i => $row) {
                    $ur->items()->create([
                        'item_id' => $row['item_id'] ?? null,
                        'component_type_id' => $row['component_type_id'] ?? null,
                        'description_raw' => $row['description_raw'] ?? null,
                        'qty' => $row['qty'],
                        'unit_id' => $row['unit_id'] ?? null,
                        'condition' => strtoupper($row['condition'] ?? 'USED'),
                        'into_stock' => $row['into_stock'] ?? false,
                        'item_no' => $i + 1,
                    ]);
                }
            }

            return $ur->refresh()->load('items');
        });
    }

    /** Hapus — hanya selagi PENDING (belum ada RI bekas). */
    public function delete(UsedReturn $ur): void
    {
        $this->assertEditable($ur);
        DB::transaction(function () use ($ur) {
            $ur->items()->delete();
            $ur->delete();
        });
    }

    /**
     * No. NPBG asal is a best-effort cross-reference (often historical, pre-Accurate,
     * or just typo'd) — unlike Stpp/Tyre/Maintenance/Manufacturing's NPBG, it is never
     * required to resolve. So this looks up the real npbg row quietly instead of using
     * AccurateDocRefs::npbgId(), which throws when the number isn't found.
     */
    private function resolveNpbgId(?string $no): ?int
    {
        $no = \App\Services\Tracking\AccurateDocRefs::normalizeNo($no);

        return $no ? Npbg::where('no_npbg', $no)->orderBy('id')->value('id') : null;
    }

    private function assertEditable(UsedReturn $ur): void
    {
        if ($ur->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => ['Pengembalian bekas yang sudah CLEAR tidak bisa diubah/dihapus.']]);
        }
    }
}
