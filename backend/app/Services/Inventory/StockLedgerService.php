<?php

namespace App\Services\Inventory;

use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single writer for stock. Every change is a `stock_movements` row + an
 * `inventory` update inside one DB transaction (docs/calculation-engine.md §6,
 * brief ATURAN MUTLAK 5–11). Nothing else may touch `inventory` directly.
 */
class StockLedgerService
{
    /** movement_type => direction applied to actual_qty */
    private const ACTUAL_DIRECTION = [
        'OPENING_BALANCE' => 1,
        'STOCK_IN' => 1,
        'RECEIVING' => 1,
        'RETURN' => 1,
        'TRANSFER_IN' => 1,
        'STOCK_OUT' => -1,
        'TRANSFER_OUT' => -1,
        'RESERVATION' => 0,
        'RELEASE_RESERVATION' => 0,
        'STOCK_ADJUSTMENT' => 0, // sets an absolute value, handled specially
        // Sets an absolute value to match Accurate's qty (stock follows Accurate while
        // Accurate is the system of record — AccurateDerivedAnalysis). Unlike
        // STOCK_ADJUSTMENT it is NOT a physical count: last_counted_at is untouched.
        'ACCURATE_SYNC' => 0,
    ];

    /**
     * Apply one movement.
     *
     * @param  array{
     *   type: string, item_id: int, warehouse_id: int, qty?: float,
     *   absolute?: float, reserve_delta?: float, reference?: Model|null,
     *   note?: string|null, created_by?: int|null, batch_uuid?: string|null
     * }  $data
     */
    public function record(array $data): StockMovement
    {
        $type = $data['type'];

        if (! array_key_exists($type, self::ACTUAL_DIRECTION)) {
            throw new RuntimeException("Unknown movement type: {$type}");
        }

        return DB::transaction(function () use ($data, $type) {
            /** @var Inventory $inv */
            $inv = Inventory::query()
                ->where('item_id', $data['item_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->lockForUpdate()
                ->firstOrCreate(
                    ['item_id' => $data['item_id'], 'warehouse_id' => $data['warehouse_id']],
                    ['actual_qty' => 0, 'reserved_qty' => 0]
                );

            $actualBefore = (float) $inv->actual_qty;
            $reservedBefore = (float) $inv->reserved_qty;

            $reserveDelta = (float) ($data['reserve_delta'] ?? 0);
            $qty = (float) ($data['qty'] ?? abs($reserveDelta));

            if ($type === 'STOCK_ADJUSTMENT' || $type === 'ACCURATE_SYNC') {
                $actualAfter = (float) ($data['absolute'] ?? $actualBefore);
                $qty = abs($actualAfter - $actualBefore);
                $direction = $actualAfter <=> $actualBefore;
            } else {
                $direction = self::ACTUAL_DIRECTION[$type];
                $actualAfter = $actualBefore + ($direction * $qty);
            }

            $reservedAfter = $reservedBefore + $reserveDelta;
            if ($type === 'ACCURATE_SYNC' && $reservedAfter > $actualAfter) {
                $reservedAfter = max(0.0, $actualAfter);
            }

            if ($actualAfter < -1e-6) {
                throw new RuntimeException("Stock would go negative for item {$data['item_id']} @ warehouse {$data['warehouse_id']}");
            }
            if ($reservedAfter < -1e-6) {
                throw new RuntimeException('Reserved stock would go negative.');
            }
            if ($reservedAfter > $actualAfter + 1e-6) {
                throw new RuntimeException('Reserved stock would exceed actual stock.');
            }

            $inv->forceFill([
                'actual_qty' => round($actualAfter, 2),
                'reserved_qty' => round($reservedAfter, 2),
                'stock_known' => $inv->stock_known || in_array($type, ['OPENING_BALANCE', 'STOCK_ADJUSTMENT', 'ACCURATE_SYNC'], true),
                'last_movement_at' => now(),
                'last_counted_at' => $type === 'STOCK_ADJUSTMENT' ? now() : $inv->last_counted_at,
            ])->save();

            $this->refreshSnapshot((int) $data['item_id']);

            $reference = $data['reference'] ?? null;

            return StockMovement::create([
                'item_id' => $data['item_id'],
                'warehouse_id' => $data['warehouse_id'],
                'movement_type' => $type,
                'direction' => $direction,
                'qty' => round($qty, 2),
                'actual_before' => round($actualBefore, 2),
                'actual_after' => round($actualAfter, 2),
                'reserved_before' => round($reservedBefore, 2),
                'reserved_after' => round($reservedAfter, 2),
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'batch_uuid' => $data['batch_uuid'] ?? (string) Str::uuid(),
                'note' => $data['note'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * inventory_snapshots (what most pages show as "stok") follows every movement at once — the rest of the
     * row (status, safety stock, priority) is refreshed by the next analysis run.
     */
    private function refreshSnapshot(int $itemId): void
    {
        $sum = Inventory::query()->where('item_id', $itemId)
            ->selectRaw('SUM(actual_qty) a, SUM(reserved_qty) r, MAX(stock_known) k')->first();
        $values = ['actual' => round((float) $sum->a, 2), 'reserved' => round((float) $sum->r, 2),
            'available' => round((float) $sum->a - (float) $sum->r, 2), 'stock_known' => (bool) $sum->k, 'updated_at' => now()];
        // the all-warehouses row (warehouse_id null) — the one the analysis writes and the pages read
        $row = DB::table('inventory_snapshots')->where('item_id', $itemId)->whereNull('warehouse_id');
        if ($row->exists()) {
            $row->update($values);
        } else {
            DB::table('inventory_snapshots')->insert($values + ['item_id' => $itemId, 'created_at' => now()]);
        }
    }

    public function openingBalance(int $itemId, int $warehouseId, float $qty, ?int $userId = null): StockMovement
    {
        return $this->record([
            'type' => 'OPENING_BALANCE',
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'qty' => $qty,
            'note' => 'Opening balance',
            'created_by' => $userId,
        ]);
    }
}
