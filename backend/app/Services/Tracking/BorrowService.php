<?php

namespace App\Services\Tracking;

use App\Models\BorrowTransaction;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Borrow — SIG meminjam barang dari pihak luar. docs/status-flow.md §8.
 * BORROWED -> PARTIAL -> RETURNED (dikembalikan lewat NPBG keluar).
 * Barang pinjaman tidak masuk stok milik SIG.
 */
class BorrowService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array{item_id?:?int, description_raw?:string, qty:float, unit_id?:?int, lender_vendor_id?:?int, lender_name?:?string, receipt_ref?:?string, borrowed_at?:?string, condition_note?:?string}  $data
     */
    public function create(User $user, array $data): BorrowTransaction
    {
        // An item picked from Master Barang brings its own description and unit.
        $item = ! empty($data['item_id']) ? \App\Models\Item::find($data['item_id']) : null;
        $date = Carbon::parse($data['borrowed_at'] ?? now());

        return BorrowTransaction::create([
            'qty_returned' => 0,
            'number' => $this->numbers->next('BORROW', 'SDA', $date),
            'item_id' => $data['item_id'] ?? null,
            'description_raw' => $data['description_raw'] ?? $item?->description ?? '-',
            'qty' => $data['qty'],
            'unit_id' => $data['unit_id'] ?? $item?->unit_id,
            'lender_vendor_id' => $data['lender_vendor_id'] ?? null,
            'lender_name' => $data['lender_name'] ?? null,
            'receipt_ref' => $data['receipt_ref'] ?? null,
            'borrowed_at' => $date->toDateString(),
            'status' => 'BORROWED',
            'condition_note' => $data['condition_note'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{qty:float, return_npbg_id?:?int, returned_at?:?string, condition_note?:?string}  $data
     */
    public function recordReturn(BorrowTransaction $borrow, array $data): BorrowTransaction
    {
        $this->assert($borrow, ['BORROWED', 'PARTIAL']);
        $qty = (float) $data['qty'];
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => ['Qty kembali harus > 0.']]);
        }
        $returned = (float) $borrow->qty_returned + $qty;
        if ($returned > (float) $borrow->qty + 1e-6) {
            throw ValidationException::withMessages(['qty' => ['Qty kembali melebihi qty dipinjam.']]);
        }

        $full = $returned >= (float) $borrow->qty - 1e-6;
        $borrow->update([
            'qty_returned' => $returned,
            'return_npbg_id' => $data['return_npbg_id'] ?? $borrow->return_npbg_id,
            'returned_at' => $data['returned_at'] ?? now()->toDateString(),
            'condition_note' => $data['condition_note'] ?? $borrow->condition_note,
            'status' => $full ? 'RETURNED' : 'PARTIAL',
        ]);

        return $borrow->refresh();
    }

    /**
     * Ubah data Borrow selagi belum ada pengembalian sama sekali.
     *
     * @param  array{item_id?:?int, description_raw?:?string, qty?:float, unit_id?:?int, lender_vendor_id?:?int, lender_name?:?string, receipt_ref?:?string, borrowed_at?:?string, condition_note?:?string}  $data
     */
    public function update(BorrowTransaction $borrow, array $data): BorrowTransaction
    {
        $this->assertEditable($borrow);

        $borrow->update([
            'item_id' => $data['item_id'] ?? $borrow->item_id,
            'description_raw' => $data['description_raw'] ?? $borrow->description_raw,
            'qty' => $data['qty'] ?? $borrow->qty,
            'unit_id' => $data['unit_id'] ?? $borrow->unit_id,
            'lender_vendor_id' => $data['lender_vendor_id'] ?? $borrow->lender_vendor_id,
            'lender_name' => $data['lender_name'] ?? $borrow->lender_name,
            'receipt_ref' => $data['receipt_ref'] ?? $borrow->receipt_ref,
            'borrowed_at' => isset($data['borrowed_at']) ? Carbon::parse($data['borrowed_at'])->toDateString() : $borrow->borrowed_at,
            'condition_note' => $data['condition_note'] ?? $borrow->condition_note,
        ]);

        return $borrow->refresh();
    }

    /** Hapus baris Borrow — hanya selagi belum ada pengembalian yang tercatat. */
    public function delete(BorrowTransaction $borrow): void
    {
        // like STPP: any row not yet (partly) returned — also one still "Belum lengkap"
        $this->assertManual($borrow);
        if ($borrow->status === 'RETURNED' || (float) $borrow->qty_returned > 0) {
            throw ValidationException::withMessages(['status' => ['Borrow yang sudah ada pengembalian tidak bisa dihapus.']]);
        }
        $borrow->delete();
    }

    private function assertEditable(BorrowTransaction $borrow): void
    {
        $this->assertManual($borrow);
        if ($borrow->status !== 'BORROWED' || (float) $borrow->qty_returned > 0) {
            throw ValidationException::withMessages([
                'status' => ['Borrow yang sudah ada pengembalian tidak bisa diubah/dihapus.'],
            ]);
        }
    }

    /** Rows derived from Accurate follow Accurate (next sync) — change them there. */
    private function assertManual(BorrowTransaction $borrow): void
    {
        if ($borrow->source === \App\Services\Tracking\LendBorrowFromAccurate::SOURCE) {
            throw ValidationException::withMessages([
                'status' => ['Data ini diambil otomatis dari Accurate (NPBG/RI) — perubahan dan pengembalian dicatat di Accurate.'],
            ]);
        }
    }

    /** @param list<string> $allowed */
    private function assert(BorrowTransaction $borrow, array $allowed): void
    {
        $this->assertManual($borrow);
        if (! in_array($borrow->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status Borrow {$borrow->status}."]]);
        }
    }
}
