<?php

namespace App\Services\Tracking;

use App\Models\BorrowTransaction;
use App\Models\LendTransaction;
use Illuminate\Support\Carbon;

/**
 * The Excel "3. Tracking Borrow & Lend" rules for rows that came from it (source EXCEL):
 *   Lend:   =IF(Kembali,"KEMBALI",IF(Keluar,IF(TODAY()>=TglPinjam+Est,"DEADLINE","SEDANG DIPINJAM"),""))
 *           Keluar = Est. Pinjam or Tanda Keluar; Kembali = Tanda Kembali or Tgl Kembali
 *   Borrow: =IF(TandaKeluar<>"","LUNAS",IF(TandaTerima<>"","SEDANG DIPINJAM",""))
 * A picture in a "Tanda …" cell is a tracking photo (slot out / in).
 * Rows entered in STOCKWISE (source MANUAL) follow the same formula when edited
 * inline, plus whatever was returned through the "Proses Kembali" action (qty_returned).
 */
class LendBorrowExcel
{
    public const LEND_FIELDS = ['description_raw', 'qty', 'unit_raw', 'requester_name', 'borrower_name', 'purpose', 'est_days', 'out_date',
        'out_doc_no', 'condition_out', 'return_doc_no', 'return_date', 'condition_in', 'return_note'];

    public const BORROW_FIELDS = ['description_raw', 'qty', 'unit_raw', 'lender_name', 'condition_note', 'borrowed_at', 'in_doc_no',
        'out_doc_no', 'returned_at', 'return_note'];

    public function __construct(private readonly TrackingPhotoService $photos) {}

    public function lendStatus(LendTransaction $l): string
    {
        $has = fn ($v) => trim((string) $v) !== '';
        // Excel: Kembali = Tanda Kembali or anything in Tgl Kembali (a date, or "LOST" / "TIDAK JADI")
        if ($has($l->return_doc_no) || ($l->source === 'EXCEL' && $has($l->condition_in))
            || ($l->id && $this->photos->has('lend', $l->id, 'in'))) {
            return 'RETURNED';
        }
        // a hand-made row returned (partly) through "Proses Kembali"
        if ($l->source === 'MANUAL' && (float) $l->qty_returned > 0) {
            return (float) $l->qty_returned >= (float) $l->qty - 1e-6 ? 'RETURNED' : 'PARTIAL_RETURN';
        }
        if ($l->return_date) {
            return 'RETURNED';
        }
        if ($l->est_days || $has($l->out_doc_no) || ($l->id && $this->photos->has('lend', $l->id, 'out'))) {
            $due = $l->out_date && $l->est_days ? $l->out_date->copy()->addDays((int) $l->est_days) : null;

            return $due && now('Asia/Jakarta')->startOfDay()->gte($due->startOfDay()) ? 'OVERDUE' : 'ON_LOAN';
        }

        return 'INCOMPLETE';
    }

    public function borrowStatus(BorrowTransaction $b): string
    {
        $has = fn ($v) => trim((string) $v) !== '';
        if ($has($b->out_doc_no) || ($b->id && $this->photos->has('borrow', $b->id, 'out'))) {
            return 'RETURNED';
        }
        // a hand-made row returned (partly) through "Proses Kembali"
        if ($b->source === 'MANUAL' && (float) $b->qty_returned > 0) {
            return (float) $b->qty_returned >= (float) $b->qty - 1e-6 ? 'RETURNED' : 'PARTIAL';
        }

        return $has($b->in_doc_no) || ($b->id && $this->photos->has('borrow', $b->id, 'in')) ? 'BORROWED' : 'INCOMPLETE';
    }

    /** Edit any Excel column of a row (from the Excel or entered here); status follows the formula. */
    public function updateLend(LendTransaction $l, array $data): LendTransaction
    {
        foreach (self::LEND_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $v = is_string($data[$f]) ? (trim($data[$f]) === '' ? null : trim($data[$f])) : $data[$f];
                if ($f === 'out_date' && $v === null) {
                    continue; // Tgl Pinjam is required in the table — an emptied cell keeps the date
                }
                $l->{$f} = in_array($f, ['out_date', 'return_date'], true) && $v ? Carbon::parse($v)->toDateString() : $v;
            }
        }
        $l->description_raw ??= '-';
        $l->due_date = $l->out_date && $l->est_days ? $l->out_date->copy()->addDays((int) $l->est_days)->toDateString() : null;
        $l->status = $this->lendStatus($l);
        $l->qty_returned = $this->qtyReturned($l->status, $l->source, $l->qty, $l->qty_returned);
        $l->save();

        return $l->refresh();
    }

    public function updateBorrow(BorrowTransaction $b, array $data): BorrowTransaction
    {
        foreach (self::BORROW_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $v = is_string($data[$f]) ? (trim($data[$f]) === '' ? null : trim($data[$f])) : $data[$f];
                if ($f === 'borrowed_at' && $v === null) {
                    continue; // Tgl Pinjam is required in the table — an emptied cell keeps the date
                }
                $b->{$f} = in_array($f, ['borrowed_at', 'returned_at'], true) && $v ? Carbon::parse($v)->toDateString() : $v;
            }
        }
        // a typed "Vendor" replaces the picked vendor record
        if (array_key_exists('lender_name', $data) && $b->isDirty('lender_name')) {
            $b->lender_vendor_id = null;
        }
        $b->description_raw ??= '-';
        $b->status = $this->borrowStatus($b);
        $b->qty_returned = $this->qtyReturned($b->status, $b->source, $b->qty, $b->qty_returned);
        $b->save();

        return $b->refresh();
    }

    /**
     * Rows from the Excel: all of it once RETURNED, else nothing. A hand-made row
     * keeps what "Proses Kembali" recorded — the formula never writes it, so
     * clearing a typed Tanda / Tgl Kembali puts the row back on loan.
     */
    private function qtyReturned(string $status, ?string $source, $qty, $returned): float
    {
        if ($source === 'MANUAL') {
            return (float) $returned;
        }

        return $status === 'RETURNED' ? (float) $qty : 0.0;
    }

    /** After a Tanda … picture was added / removed. */
    public function recompute(string $entity, int $id): void
    {
        if ($entity === 'lend' && ($l = LendTransaction::find($id)) && in_array($l->source, ['EXCEL', 'MANUAL'], true)) {
            $l->status = $this->lendStatus($l);
            $l->qty_returned = $this->qtyReturned($l->status, $l->source, $l->qty, $l->qty_returned);
            $l->save();
        }
        if ($entity === 'borrow' && ($b = BorrowTransaction::find($id)) && in_array($b->source, ['EXCEL', 'MANUAL'], true)) {
            $b->status = $this->borrowStatus($b);
            $b->qty_returned = $this->qtyReturned($b->status, $b->source, $b->qty, $b->qty_returned);
            $b->save();
        }
    }
}
