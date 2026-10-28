<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockLedgerService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock Opname — docs/status-flow.md §7, business-process.md BP-4, brief §I/§J/§AP.
 * Physical count -> admin review -> APPROVED creates a stock_adjustment +
 * STOCK_ADJUSTMENT movement (the ONLY way opname changes actual stock).
 */
class StockOpnameService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * @param  list<int>|null  $itemIds  null = FULL/OPENING (every active item in Master Barang
     *                                    assigned to this warehouse, or unassigned)
     *
     * Item lines come from Master Barang (`items`), not from `inventory` — the whole point of an
     * OPENING opname (docs/assumptions.md NC-4: initial stock is established BY doing an opname)
     * is that no inventory row exists yet, so requiring one first made that type permanently
     * produce zero lines. A FULL/PARTIAL opname for an item with no inventory row yet also just
     * starts that line's `system_qty` at 0 rather than silently dropping the item.
     */
    public function schedule(User $user, int $warehouseId, string $date, string $type, ?array $itemIds): StockOpname
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        return DB::transaction(function () use ($user, $warehouse, $date, $type, $itemIds) {
            $opname = StockOpname::create([
                'number' => $this->numbers->next('SO', 'GD'),
                'warehouse_id' => $warehouse->id,
                'site_id' => $warehouse->site_id,
                'scheduled_date' => $date,
                'type' => $type,
                'status' => 'SCHEDULED',
                'created_by' => $user->id,
            ]);

            $items = Item::query()
                ->where('is_active', true)
                ->when(
                    $itemIds,
                    fn ($q) => $q->whereIn('id', $itemIds),
                    // No explicit item list (FULL/OPENING): every active item that either belongs
                    // to this warehouse or isn't assigned to any warehouse yet (today, that's
                    // effectively every item — default_warehouse_id isn't populated post-Accurate
                    // sync — so this degrades to "all active items" rather than an empty opname).
                    fn ($q) => $q->where(fn ($w) => $w->where('default_warehouse_id', $warehouse->id)
                        ->orWhereNull('default_warehouse_id'))
                )
                ->get(['id']);

            $systemQtyByItem = Inventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('item_id', $items->pluck('id'))
                ->pluck('actual_qty', 'item_id');

            foreach ($items as $item) {
                $opname->items()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'system_qty' => $systemQtyByItem[$item->id] ?? 0,
                ]);
            }

            NotificationDispatcher::toPermission(
                'opname.count', 'opname', 'info',
                'Stock Opname dijadwalkan',
                "Opname {$opname->number} dijadwalkan {$date} di {$warehouse->name}.",
                "/stock-opnames/{$opname->id}",
                exceptUserId: $user->id,
            );

            return $opname->load('items');
        });
    }

    public function start(StockOpname $opname, User $user): StockOpname
    {
        $this->assertNotAccurate($opname);
        $this->assert($opname, ['SCHEDULED', 'RECOUNT_REQUIRED']);
        $opname->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'counted_by' => $user->id]);

        return $opname;
    }

    public function count(StockOpnameItem $line, float $physicalQty, ?string $note): StockOpnameItem
    {
        $this->assertNotAccurate($line->opname);
        $this->assert($line->opname, ['IN_PROGRESS']);

        $line->update([
            'physical_qty' => $physicalQty,
            'note' => $note,
            'count_status' => 'COUNTED',
        ]);

        return $line;
    }

    public function submit(StockOpname $opname): StockOpname
    {
        $this->assertNotAccurate($opname);
        $this->assert($opname, ['IN_PROGRESS']);

        $items = $opname->items()->with('item:id,code')->get();

        $uncounted = $items->filter(fn ($i) => $i->physical_qty === null)->count();
        if ($uncounted > 0) {
            throw ValidationException::withMessages([
                'items' => ["Masih {$uncounted} barang belum diisi jumlah fisiknya. Isi semua baris sebelum submit."],
            ]);
        }

        // TC-SO-002: selisih tanpa catatan -> submit ditolak. Names the item codes so the
        // counter can find the rows (only that the row differs, never the system qty).
        $missingNote = $items->filter(fn ($i) => abs((float) $i->difference) > 1e-6 && ! filled($i->note));
        if ($missingNote->isNotEmpty()) {
            $codes = $missingNote->take(10)->map(fn ($i) => $i->item?->code ?? "#{$i->item_id}")->implode(', ');
            $more = $missingNote->count() > 10 ? ' dan '.($missingNote->count() - 10).' lainnya' : '';
            throw ValidationException::withMessages([
                'items' => ["Hasil hitung {$missingNote->count()} barang berbeda dengan stok — wajib isi catatan (alasan): {$codes}{$more}."],
            ]);
        }

        $opname->update(['status' => 'PENDING_REVIEW', 'submitted_at' => now()]);

        NotificationDispatcher::toPermission(
            'opname.approve', 'opname', 'info',
            'Stock Opname menunggu review',
            "Opname {$opname->number} sudah dihitung, menunggu review.",
            "/stock-opnames/{$opname->id}",
            exceptUserId: $opname->counted_by,
        );

        return $opname->fresh('items');
    }

    /**
     * Review the whole opname.
     *
     * @param  array<int, array{id:int, decision:string}>  $decisions  per stock_opname_item
     */
    public function review(StockOpname $opname, User $reviewer, array $decisions, ?string $note): StockOpname
    {
        // Real ITEMADJ rows land in PENDING_REVIEW too (see AccurateSyncService::syncOneStockOpname)
        // — without this guard, review() would create a stock_adjustment + STOCK_ADJUSTMENT
        // movement for an adjustment Accurate already recorded and (possibly) already posted itself.
        $this->assertNotAccurate($opname);
        $this->assert($opname, ['PENDING_REVIEW']);

        return DB::transaction(function () use ($opname, $reviewer, $decisions, $note) {
            $map = collect($decisions)->keyBy('id');
            $anyRecount = false;
            $batch = (string) Str::uuid();

            foreach ($opname->items()->get() as $line) {
                $decision = $map[$line->id]['decision'] ?? 'APPROVED';
                $line->update(['review_status' => $decision]);

                if ($decision === 'RECOUNT') {
                    $anyRecount = true;

                    continue;
                }
                if ($decision !== 'APPROVED') {
                    continue;
                }
                if (abs((float) $line->difference) < 1e-6) {
                    continue;
                }

                // APPROVED + selisih -> stock adjustment (TC-SO-004)
                $movement = $this->ledger->record([
                    'type' => 'STOCK_ADJUSTMENT',
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'absolute' => (float) $line->physical_qty,
                    'reference' => $line,
                    'batch_uuid' => $batch,
                    'created_by' => $reviewer->id,
                    'note' => "Opname {$opname->number}: {$line->note}",
                ]);

                StockAdjustment::create([
                    'stock_opname_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'qty_before' => $movement->actual_before,
                    'qty_after' => $movement->actual_after,
                    'difference' => $movement->actual_after - $movement->actual_before,
                    'reason' => $line->note ?? 'Stock opname',
                    'stock_movement_id' => $movement->id,
                    'approved_by' => $reviewer->id,
                    'approved_at' => now(),
                    'created_by' => $reviewer->id,
                ]);
            }

            $opname->update([
                'status' => $anyRecount ? 'RECOUNT_REQUIRED' : 'COMPLETED',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if ($anyRecount) {
                NotificationDispatcher::toPermission(
                    'opname.count', 'opname', 'warning',
                    'Stock Opname perlu hitung ulang',
                    "Opname {$opname->number}: beberapa item perlu dihitung ulang.",
                    "/stock-opnames/{$opname->id}",
                    exceptUserId: $reviewer->id,
                );
            }

            return $opname->fresh('items');
        });
    }

    /** @param list<string> $allowed */
    private function assert(StockOpname $opname, array $allowed): void
    {
        if (! in_array($opname->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Aksi tidak valid untuk status opname {$opname->status}."],
            ]);
        }
    }

    private function assertNotAccurate(StockOpname $opname): void
    {
        abort_if($opname->accurate_itemadj_id !== null, 422, 'Stock Opname ini berasal dari Accurate — sudah berupa dokumen final, tidak melalui alur hitung/review internal.');
    }
}
