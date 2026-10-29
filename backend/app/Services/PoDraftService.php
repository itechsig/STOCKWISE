<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderQuote;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft PO — like the Draft PPB: every item of a STOCKWISE PPB the Direktur approved waits here
 * (its "Menunggu PO" line). Purchasing notes each vendor's price (the comparison), picks items and
 * one vendor: they become ONE PO (one PO = one vendor), submitted to the Direktur.
 */
class PoDraftService
{
    public function __construct(private DocumentNumberService $numbers) {}

    /** the items waiting for their PO (STOCKWISE PPBs only: Accurate's get their PO in Accurate) */
    public static function waiting(): Builder
    {
        $nos = DB::table('ppb')->join('purchase_order_items as i', 'i.approved_ppb_id', '=', 'ppb.id')
            ->where('i.line_status', PpbApprovalService::WAITING)->distinct()->pluck('ppb.no_ppb');
        $stockwise = PpbApprovalService::stockwiseNos($nos)->values()->all();

        return PurchaseOrderItem::query()->where('line_status', PpbApprovalService::WAITING)
            ->whereHas('purchaseOrder', fn ($p) => $p->where('status', PpbApprovalService::WAITING))
            ->whereHas('approvedPpb', fn ($p) => $p->whereIn('no_ppb', $stockwise));
    }

    /** A vendor's price for a waiting item (one per vendor: saved again = changed). */
    public function quote(PurchaseOrderItem $item, User $user, int $vendorId, float $price, ?string $note): PurchaseOrderQuote
    {
        $this->expectWaiting($item);

        return PurchaseOrderQuote::updateOrCreate(
            ['purchase_order_item_id' => $item->id, 'vendor_id' => $vendorId],
            ['unit_price' => round($price, 2), 'note' => $note ?: null, 'created_by' => $user->id],
        );
    }

    /**
     * The picked items become one PO of one vendor: their price is the one given, else that vendor's
     * quote. The emptied "Menunggu PO" entries go.
     *
     * @param  list<int>  $ids
     * @param  array{vendor_id:int, expected_date?:?string, notes?:?string, lines?:list<array{id:int, qty?:float|null, unit_price?:float|null}>}  $data
     */
    public function submit(User $user, array $ids, array $data): PurchaseOrder
    {
        $po = DB::transaction(function () use ($user, $ids, $data) {
            $items = self::waiting()->whereIn('id', $ids)->with('approvedPpb:id,no_ppb', 'quotes')->lockForUpdate()->orderBy('id')->get();
            if ($items->isEmpty() || $items->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['line_ids' => 'Sebagian barang sudah jadi PO / tidak di Draft PO lagi — muat ulang daftar.']);
            }
            $given = collect($data['lines'] ?? [])->keyBy('id');
            $vendorId = (int) $data['vendor_id'];
            $values = [];
            foreach ($items as $i => $item) {
                $in = $given->get($item->id, []);
                $qty = round((float) ($in['qty'] ?? $item->qty), 2);
                $price = $in['unit_price'] ?? $item->quotes->firstWhere('vendor_id', $vendorId)?->unit_price;
                if ($qty <= 0) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => "Jumlah {$item->description_raw} harus lebih dari 0."]);
                }
                if ($price === null) {
                    throw ValidationException::withMessages(["lines.{$i}.unit_price" => "Isi harga {$item->description_raw}."]);
                }
                $values[$item->id] = [$qty, round((float) $price, 2)];
            }

            $nos = $items->pluck('approvedPpb.no_ppb')->filter()->unique()->values();
            $date = now();
            $number = $this->numbers->next('PO', 'PPB', $date);
            preg_match('/(\d+)$/', $number, $m);
            $po = PurchaseOrder::create([
                'number' => $number, 'prefix' => 'PPB', 'year' => (int) $date->format('y'), 'month' => (int) $date->format('n'), 'sequence' => (int) $m[1],
                'date' => $date->toDateString(), 'vendor_id' => $vendorId, 'site_id' => $items->first()->purchaseOrder->site_id ?? $user->site_id ?? DB::table('sites')->value('id'),
                'expected_date' => $data['expected_date'] ?? null, 'status' => 'DRAFT',
                'notes' => ($data['notes'] ?? null) ?: 'Dari Draft PO · PPB '.$nos->implode(', '),
                'from_ppb_approval' => $nos->count() === 1 ? $nos->first() : null,
                'created_by' => $user->id,
            ]);
            $entries = $items->pluck('purchase_order_id')->unique();
            foreach ($items as $item) {
                [$qty, $price] = $values[$item->id];
                // the line itself moves (it keeps its PPB line and its price comparison)
                $item->update(['purchase_order_id' => $po->id, 'vendor_id' => $vendorId, 'qty' => $qty, 'unit_price' => $price,
                    'line_total' => round($qty * $price, 2), 'line_status' => 'PENDING']);
            }
            $subtotal = (float) $po->items()->sum('line_total');
            $po->update(['subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal]);
            PurchaseOrder::whereIn('id', $entries)->where('status', PpbApprovalService::WAITING)->doesntHave('items')->get()->each->delete();
            DB::table(PurchaseOrderService::LOG)->insert(['purchase_order_id' => $po->id, 'action' => 'DIAJUKAN',
                'note' => 'PO dibuat dari Draft PO (PPB '.$nos->implode(', ').')', 'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now()]);

            return $po;
        });
        PurchaseOrderService::notifyDirectorOf($po, $user);

        return $po;
    }

    public function expectWaiting(PurchaseOrderItem $item): void
    {
        if (! self::waiting()->whereKey($item->id)->exists()) {
            throw ValidationException::withMessages(['line' => 'Barang ini sudah jadi PO / tidak di Draft PO lagi.']);
        }
    }
}
