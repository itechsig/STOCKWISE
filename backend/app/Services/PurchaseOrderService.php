<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase Order — docs/status-flow.md §5.
 * DRAFT -> APPROVED -> SENT -> PARTIAL_RECEIVED -> RECEIVED -> CLOSED.
 *
 * A STOCKWISE PO (accurate_po_id null: made here, or completed from the "Menunggu PO" entry of an
 * approved STOCKWISE PPB) is approved by the Direktur (po.approve_director). Its log
 * (purchase_order_approvals): DIAJUKAN → APPROVE, or REJECT = back to purchasing to fix and submit
 * again. Goods arrive through RI, maybe in parts (receive()). Accurate's POs are never touched.
 */
class PurchaseOrderService
{
    public const LOG = 'purchase_order_approvals';

    public const DIRECTOR = 'po.approve_director';

    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * Manual PO — created DRAFT, same internal workflow Accurate-synced POs
     * never go through (those arrive already APPROVED/SENT/etc. with
     * accurate_po_id set). Numbered distinctly ("PO/MANUAL/26/X/001") so it
     * never collides with a real Accurate PO number.
     *
     * @param array{vendor_id:int,site_id:int,date:string,expected_date:?string,notes:?string,items:list<array{item_id:?int,description_raw:string,qty:float,unit_id:?int,unit_price:float}>} $data
     */
    public function create(User $user, array $data): PurchaseOrder
    {
        $po = DB::transaction(function () use ($user, $data) {
            $date = \Illuminate\Support\Carbon::parse($data['date']);
            $number = $this->numbers->next('PO', 'MANUAL', $date);
            preg_match('/(\d+)$/', $number, $m);

            $po = PurchaseOrder::create([
                'number' => $number,
                'prefix' => 'MANUAL',
                'year' => (int) $date->format('y'),
                'month' => (int) $date->format('n'),
                'sequence' => (int) $m[1],
                'date' => $data['date'],
                'vendor_id' => $data['vendor_id'],
                'site_id' => $data['site_id'],
                'expected_date' => $data['expected_date'] ?? null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $subtotal = 0.0;
            foreach ($data['items'] as $line) {
                $lineTotal = (float) $line['qty'] * (float) $line['unit_price'];
                $subtotal += $lineTotal;
                $po->items()->create([
                    'item_id' => $line['item_id'] ?? null,
                    'description_raw' => $line['description_raw'],
                    'qty' => $line['qty'],
                    'unit_id' => $line['unit_id'] ?? null,
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $po->update(['subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal]);
            $this->log($po, 'DIAJUKAN', $user, null);

            return $po;
        });
        $this->notifyDirector($po, $user);

        return $po;
    }

    /** STOCKWISE PO's approval: MENUNGGU_DIREKTUR / DIKEMBALIKAN / DISETUJUI, null for Accurate's / not submitted. */
    public static function approvalStatus(PurchaseOrder $po): ?string
    {
        if ($po->accurate_po_id !== null) {
            return null;
        }

        return match (DB::table(self::LOG)->where('purchase_order_id', $po->id)->whereIn('action', ['DIAJUKAN', 'REJECT', 'APPROVE'])->orderByDesc('id')->value('action')) {
            'DIAJUKAN' => 'MENUNGGU_DIREKTUR',
            'REJECT' => 'DIKEMBALIKAN',
            'APPROVE' => 'DISETUJUI',
            default => null,
        };
    }

    /** @return Collection<int, object> the approval log, oldest first */
    public static function approvalLog(PurchaseOrder $po): Collection
    {
        return DB::table(self::LOG)->where('purchase_order_id', $po->id)->orderBy('id')->get(['id', 'action', 'note', 'user_name', 'created_at'])
            ->map(fn ($r) => tap($r, fn ($r) => $r->created_at = $r->created_at ? \Illuminate\Support\Carbon::parse($r->created_at, config('app.timezone'))->toIso8601String() : null));
    }

    /** May this PO be completed / fixed here: a STOCKWISE PPB's "Menunggu PO" entry, or a STOCKWISE PO not approved yet. */
    public static function editable(PurchaseOrder $po): bool
    {
        if ($po->accurate_po_id !== null) {
            return false;
        }
        if ($po->status === PpbApprovalService::WAITING) {
            return $po->from_ppb_approval !== null && PpbApprovalService::isStockwise($po->from_ppb_approval);
        }

        return $po->status === 'DRAFT' && self::approvalStatus($po) !== 'DISETUJUI';
    }

    /**
     * Purchasing completes the entry (vendor, prices, maybe quantities) — or fixes a PO the Direktur
     * sent back — and submits it to the Direktur.
     *
     * One PO = one vendor: every line is bought from vendor_id.
     *
     * @param  array{vendor_id:int, expected_date?:?string, notes?:?string, lines:list<array{id:int, qty:float, unit_price:float}>}  $data
     */
    public function complete(PurchaseOrder $po, User $user, array $data): PurchaseOrder
    {
        DB::transaction(function () use (&$po, $user, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if (! self::editable($po)) {
                throw ValidationException::withMessages(['status' => 'PO ini tidak bisa diubah lagi.']);
            }
            $items = $po->items()->get()->keyBy('id');
            foreach ($data['lines'] as $i => $in) {
                $line = $items->get($in['id']) ?? throw ValidationException::withMessages(["lines.{$i}.id" => 'Baris ini bukan bagian dari PO.']);
                $qty = round((float) $in['qty'], 2);
                $price = round((float) $in['unit_price'], 2);
                if ($qty <= 0) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => "Jumlah {$line->description_raw} harus lebih dari 0."]);
                }
                // one PO = one vendor: every line is bought from the PO's vendor
                $vendorId = (int) $data['vendor_id'];
                $line->update(['qty' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2), 'line_status' => 'PENDING', 'vendor_id' => $vendorId]);
            }
            $subtotal = (float) $po->items()->sum('line_total');
            $first = $po->status === PpbApprovalService::WAITING;
            $po->update([
                'vendor_id' => (int) $data['vendor_id'],
                'expected_date' => $data['expected_date'] ?? $po->expected_date,
                'notes' => array_key_exists('notes', $data) && $data['notes'] !== null ? $data['notes'] : $po->notes,
                'date' => $first ? now()->toDateString() : $po->date,
                'status' => 'DRAFT', 'subtotal' => $subtotal, 'tax' => 0, 'total' => $subtotal,
                'approved_by' => null, 'approved_at' => null,
            ]);
            $this->log($po, 'DIAJUKAN', $user, $first ? 'PO dilengkapi dari PPB '.$po->from_ppb_approval : 'PO diperbaiki & diajukan lagi');
        });
        $this->notifyDirector($po, $user);

        return $po;
    }

    public function approve(PurchaseOrder $po, User $user): PurchaseOrder
    {
        abort_if($po->accurate_po_id !== null, 422, 'PO ini berasal dari Accurate — sudah berupa dokumen final, tidak melalui alur approve internal.');
        $this->assert($po, ['DRAFT']);
        if (! $user->hasPermission(self::DIRECTOR)) {
            throw ValidationException::withMessages(['status' => 'PO STOCKWISE disetujui oleh Direktur.']);
        }
        if (self::approvalStatus($po) === 'DIKEMBALIKAN') {
            throw ValidationException::withMessages(['status' => 'PO ini dikembalikan ke purchasing — tunggu diperbaiki & diajukan lagi.']);
        }
        DB::transaction(function () use ($po, $user) {
            $po->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()]);
            $this->log($po, 'APPROVE', $user, null);
        });
        NotificationDispatcher::toPermission('po.send', 'po', 'success', "PO {$po->number} disetujui Direktur",
            'Kirim PO ke vendor.', "/purchase-orders/{$po->id}", $user->id);
        app(ItemRequestFulfillmentService::class)->refreshWaiting(); // its Request Barang now wait for the goods

        return $po;
    }

    /** The Direktur sends the PO back to purchasing (with a reason) to fix and submit again. */
    public function reject(PurchaseOrder $po, User $user, string $note): PurchaseOrder
    {
        abort_if($po->accurate_po_id !== null, 422, 'PO ini berasal dari Accurate.');
        $this->assert($po, ['DRAFT']);
        if (! $user->hasPermission(self::DIRECTOR)) {
            throw ValidationException::withMessages(['status' => 'Hanya Direktur yang bisa mengembalikan PO STOCKWISE.']);
        }
        if (self::approvalStatus($po) !== 'MENUNGGU_DIREKTUR') {
            throw ValidationException::withMessages(['status' => 'PO ini tidak sedang menunggu approval Direktur.']);
        }
        $this->log($po, 'REJECT', $user, $note);
        NotificationDispatcher::toPermission('po.create', 'po', 'warning', "PO {$po->number} dikembalikan Direktur",
            "{$user->name}: {$note}", "/purchase-orders/{$po->id}", $user->id);

        return $po;
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        abort_if($po->accurate_po_id !== null, 422, 'PO ini berasal dari Accurate — sudah berupa dokumen final, tidak melalui alur approve internal.');
        $this->assert($po, ['APPROVED']);
        $po->update(['status' => 'SENT']);

        return $po;
    }

    /**
     * The proof of purchase (nota / invoice) of an approved STOCKWISE PO: kept with the PO and the PO is
     * now bought (DIBELI) — the goods come in by RI. A later proof replaces it (status stays).
     */
    public function uploadProof(PurchaseOrder $po, User $user, string $bytes, string $ext, ?string $fileName): PurchaseOrder
    {
        abort_if($po->accurate_po_id !== null, 422, 'PO ini berasal dari Accurate.');
        if (! in_array($po->status, ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED', 'RECEIVED'], true)) {
            throw ValidationException::withMessages(['status' => 'Bukti pembelian diunggah setelah PO disetujui Direktur.']);
        }
        DB::transaction(function () use ($po, $user, $bytes, $ext, $fileName) {
            app(\App\Services\Tracking\TrackingPhotoService::class)->put('purchase_order', $po->id, 'bukti', $bytes, $ext, $fileName, 'UPLOAD', $user->id);
            if (in_array($po->status, ['APPROVED', 'SENT'], true)) {
                $po->update(['status' => 'DIBELI']);
            }
            $this->log($po, 'DIBELI', $user, 'Bukti pembelian diunggah'.($fileName ? ": {$fileName}" : ''));
        });
        app(ItemRequestFulfillmentService::class)->refreshWaiting(); // its Request Barang: goods on their way

        return $po;
    }

    public function cancel(PurchaseOrder $po, string $reason): PurchaseOrder
    {
        abort_if($po->accurate_po_id !== null, 422, 'PO ini berasal dari Accurate — hanya bisa diubah/dibatalkan di Accurate.');
        abort_if(in_array($po->status, ['RECEIVED', 'CLOSED', 'CANCELLED', 'PARTIAL_RECEIVED'], true), 422, 'PO tidak bisa dibatalkan.');
        abort_if($po->items()->where('qty_received', '>', 0)->exists(), 422, 'PO sudah ada penerimaan.');
        // a STOCKWISE PPB's PO cancelled: the approved PPB gets a new "Menunggu PO" entry at the next scan
        $po->update(['status' => 'CANCELLED', 'notes' => trim(($po->notes ?? '')."\nDibatalkan: {$reason}")]);

        return $po;
    }

    /**
     * Goods of a STOCKWISE PO line arrived (an RI): received so far goes up. Less than ordered →
     * PARTIAL_RECEIVED (more RI may follow); as ordered or more (the vendor sent extra, it all goes to
     * stock) → RECEIVED. The PO follows its lines.
     */
    public function receive(PurchaseOrderItem $line, float $qty): void
    {
        $po = PurchaseOrder::lockForUpdate()->findOrFail($line->purchase_order_id);
        if ($po->accurate_po_id !== null || ! in_array($po->status, ['APPROVED', 'SENT', 'DIBELI', 'PARTIAL_RECEIVED'], true)) {
            throw ValidationException::withMessages(['purchase_order_item_id' => 'PO ini belum disetujui / sudah selesai — tidak bisa diterima.']);
        }
        $line = PurchaseOrderItem::lockForUpdate()->findOrFail($line->id);
        if ((float) $line->qty_received + 0.0001 >= (float) $line->qty) {
            throw ValidationException::withMessages(['purchase_order_item_id' => 'Barang PO ini sudah diterima penuh.']);
        }
        $got = round((float) $line->qty_received + $qty, 2);
        $line->update(['qty_received' => $got, 'line_status' => $got + 0.0001 >= (float) $line->qty ? 'RECEIVED' : 'PARTIAL_RECEIVED']);
        $open = $po->items()->whereColumn('qty_received', '<', 'qty')->exists();
        $po->update(['status' => $open ? 'PARTIAL_RECEIVED' : 'RECEIVED']);
    }

    private function log(PurchaseOrder $po, string $action, User $user, ?string $note): void
    {
        DB::table(self::LOG)->insert([
            'purchase_order_id' => $po->id, 'action' => $action, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
            'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
        ]);
    }

    private function notifyDirector(PurchaseOrder $po, User $user): void
    {
        self::notifyDirectorOf($po, $user);
    }

    /** a STOCKWISE PO waits for the Direktur (also a PO made from the Draft PO) */
    public static function notifyDirectorOf(PurchaseOrder $po, User $user): void
    {
        NotificationDispatcher::toPermission(self::DIRECTOR, 'po', 'info', "PO {$po->number} menunggu approval Direktur",
            'Total Rp '.number_format((float) $po->total, 0, ',', '.').'.', "/purchase-orders/{$po->id}", $user->id);
    }

    /** @param list<string> $allowed */
    private function assert(PurchaseOrder $po, array $allowed): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status PO {$po->status}."]]);
        }
    }
}
