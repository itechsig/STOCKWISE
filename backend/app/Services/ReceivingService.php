<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Receiving;
use App\Models\User;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Receiving (RI) — docs/status-flow.md §6. Stock is NOT added before CONFIRMED (ATURAN MUTLAK 8).
 */
class ReceivingService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * @param  array{purchase_order_id?:?int, warehouse_id:int, source_type?:string, vendor_id?:?int, surat_jalan_no?:?string, lines:array<int,array{purchase_order_item_id?:?int, item_id?:?int, description_raw?:string, qty_received:float, qty_accepted?:?float, unit_id?:?int, into_stock?:bool, condition_note?:?string}>}  $data
     */
    public function create(User $user, array $data): Receiving
    {
        $po = ! empty($data['purchase_order_id']) ? PurchaseOrder::findOrFail($data['purchase_order_id']) : null;

        return DB::transaction(function () use ($user, $data, $po) {
            $date = now();
            $number = $this->numbers->next('RI', 'NV', $date);
            [, , $yy, , $seq] = explode('/', $number);

            $ri = Receiving::create([
                'number' => $number,
                'prefix' => 'NV',
                'year' => (int) $yy,
                'month' => (int) $date->format('n'),
                'sequence' => (int) $seq,
                'date' => $date->toDateString(),
                'source_type' => $data['source_type'] ?? 'PURCHASE',
                'purchase_order_id' => $po?->id,
                'vendor_id' => $data['vendor_id'] ?? $po?->vendor_id,
                'surat_jalan_no' => $data['surat_jalan_no'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'site_id' => $po?->site_id ?? $user->site_id,
                'status' => 'CHECKING',
                'created_by' => $user->id,
            ]);

            foreach ($data['lines'] as $row) {
                $item = ! empty($row['item_id']) ? Item::find($row['item_id']) : null;
                $accepted = $row['qty_accepted'] ?? $row['qty_received'];

                $ri->items()->create([
                    'purchase_order_item_id' => $row['purchase_order_item_id'] ?? null,
                    'item_id' => $item?->id,
                    'description_raw' => $row['description_raw'] ?? $item?->description ?? '-',
                    'qty_received' => $row['qty_received'],
                    'qty_accepted' => $accepted,
                    'qty_rejected' => $row['qty_received'] - $accepted,
                    'unit_id' => $row['unit_id'] ?? $item?->unit_id,
                    'into_stock' => $row['into_stock'] ?? true,
                    'condition_note' => $row['condition_note'] ?? null,
                    'warehouse_id' => $data['warehouse_id'],
                ]);
            }

            return $ri->load('items');
        });
    }

    public function confirm(Receiving $ri, User $user): Receiving
    {
        $this->assert($ri, ['DRAFT', 'CHECKING', 'PARTIAL']);

        return DB::transaction(function () use ($ri, $user) {
            $batch = (string) Str::uuid();
            $movementType = match ($ri->source_type) {
                'PURCHASE', 'TRANSFER_IN', 'OPENING' => 'RECEIVING',
                'MANUFACTURING_OUTPUT' => 'STOCK_IN',
                default => 'RETURN',
            };

            foreach ($ri->items()->get() as $line) {
                if ($line->item_id === null || ! $line->into_stock || $line->qty_accepted <= 0) {
                    continue;
                }
                $this->ledger->record([
                    'type' => $movementType,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $line->warehouse_id,
                    'qty' => $line->qty_accepted,
                    'reference' => $line,
                    'batch_uuid' => $batch,
                    'created_by' => $user->id,
                    'note' => "Receiving {$ri->number}",
                ]);

                if ($line->purchase_order_item_id) {
                    PurchaseOrderItem::where('id', $line->purchase_order_item_id)
                        ->increment('qty_received', (float) $line->qty_accepted);
                }
            }

            $ri->update(['status' => 'CONFIRMED', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);
            $this->syncPo($ri);

            return $ri->fresh('items');
        });
    }

    public function reject(Receiving $ri, string $reason): Receiving
    {
        $this->assert($ri, ['DRAFT', 'CHECKING']);
        $ri->update(['status' => 'REJECTED', 'notes' => trim(($ri->notes ?? '')."\nDitolak: {$reason}")]);

        return $ri;
    }

    private function syncPo(Receiving $ri): void
    {
        $po = $ri->purchaseOrder;
        if (! $po) {
            return;
        }

        $po->load('items');
        $fully = $po->items->every(fn ($i) => (float) $i->qty_received >= (float) $i->qty - 1e-6);
        $any = $po->items->contains(fn ($i) => (float) $i->qty_received > 0);

        $po->update(['status' => $fully ? 'RECEIVED' : ($any ? 'PARTIAL_RECEIVED' : $po->status)]);

        foreach ($po->items as $i) {
            $i->update([
                'line_status' => (float) $i->qty_received >= (float) $i->qty - 1e-6
                    ? 'RECEIVED'
                    : ((float) $i->qty_received > 0 ? 'PARTIAL_RECEIVED' : $i->line_status),
            ]);
        }
    }

    /** @param list<string> $allowed */
    private function assert(Receiving $ri, array $allowed): void
    {
        if (! in_array($ri->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status RI {$ri->status}."]]);
        }
    }
}
