<?php

namespace App\Services\Tracking\Import;

use App\Models\BorrowTransaction;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ri;
use App\Services\DocumentNumberService;
use App\Services\Tracking\LendBorrowExcel;
use App\Services\Tracking\LendBorrowFromAccurate;
use App\Services\Tracking\TrackingPhotoService;
use App\Support\WpsCellImages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * "3. Tracking Borrow & Lend.xlsx" into Lend / Borrow, row for row (source EXCEL).
 * A row whose "Tanda Keluar" (Lend: NPBG) / "Tanda Terima" (Borrow: RI) is the same
 * Accurate line STOCKWISE already derived a loan from takes that loan over — the
 * sync leaves it alone from then on. A picture in a "Tanda …" cell becomes a
 * tracking photo (Lend: out = Tanda Keluar, in = Tanda Kembali; Borrow: in = Tanda
 * Terima, out = Tanda Keluar). Status = the value the Excel formula shows.
 */
class LendBorrowExcelImporter
{
    use ReadsExcelCells;

    public function __construct(
        private readonly TrackingPhotoService $photos,
        private readonly DocumentNumberService $numbers,
        private readonly LendBorrowExcel $rules,
    ) {}

    public function import(string $xlsx, ?string $photoDir, bool $dryRun = false): array
    {
        $reader = IOFactory::createReaderForFile($xlsx);
        $reader->setReadDataOnly(true);
        $book = $reader->load($xlsx);
        $images = new WpsCellImages($xlsx);
        $st = ['lend_rows' => 0, 'lend_replaced_accurate' => 0, 'borrow_rows' => 0, 'borrow_replaced_accurate' => 0, 'pictures' => 0,
            'KEMBALI' => 0, 'DEADLINE' => 0, 'SEDANG DIPINJAM (lend)' => 0, 'LUNAS' => 0, 'SEDANG DIPINJAM (borrow)' => 0, 'kosong' => 0];

        DB::beginTransaction();
        try {
            $this->lend($book->getSheetByName('Lend'), $images, $dryRun, $st);
            $this->borrow($book->getSheetByName('Borrow'), $images, $dryRun, $st);
            // loans from Accurate the Excel does not have continue the "No" after it
            foreach ([[LendTransaction::class, 'out_date'], [BorrowTransaction::class, 'borrowed_at']] as [$model, $date]) {
                $next = (int) $model::max('sheet_no');
                $model::whereNull('sheet_no')->orderBy($date)->orderBy('id')->get()
                    ->each(function ($r) use (&$next) { $r->forceFill(['sheet_no' => ++$next])->saveQuietly(); });
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $st;
    }

    /** Lend: No | Tgl Pinjam | Deskripsi | Qty | Satuan | Peminta | Keperluan | Est. Pinjam | Tanda Keluar | Ket Keluar | Status | Tanda Kembali | Tgl Kembali | Ket Kembali */
    private function lend(?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, WpsCellImages $images, bool $dryRun, array &$st): void
    {
        // The Excel is the record of every NPBG it lists as "Tanda Keluar": loans STOCKWISE
        // derived from those documents (Accurate) are replaced by the Excel rows.
        $docs = [];
        for ($r = 6; $ws && $r <= $ws->getHighestDataRow('D'); $r++) {
            $c = $ws->getCell("J$r")->getValue();
            if (! WpsCellImages::idOf($c) && ($no = $this->docNo($c)) && $no !== '-') {
                $docs[$no] = true;
            }
        }
        $st['lend_replaced_accurate'] = $this->replaceAccurate(LendTransaction::class, 'out_npbg_id', Npbg::whereIn('no_npbg', array_keys($docs))->pluck('id'), 'lend');
        LendTransaction::where('source', 'EXCEL')->update(['out_npbg_id' => null]);
        $used = [];
        $n = 0;
        for ($r = 6; $ws && $r <= $ws->getHighestDataRow('D'); $r++) {
            $desc = $this->text($ws->getCell("D$r")->getValue());
            if (! $desc) {
                continue;
            }
            $n++;
            $st['lend_rows']++;
            $outCell = $ws->getCell("J$r")->getValue();
            $backCell = $ws->getCell("M$r")->getValue();
            $outDoc = WpsCellImages::idOf($outCell) ? null : $this->docNo($outCell);
            $backDoc = WpsCellImages::idOf($backCell) ? null : $this->text($backCell);
            $date = $this->date($ws->getCell("C$r")->getValue()) ?? now()->toDateString();

            $l = LendTransaction::where('source', 'EXCEL')->where('sheet_no', $n)->first()
                ?? new LendTransaction(['number' => $this->numbers->next('LEND', 'SDA', Carbon::parse($date)), 'qty_returned' => 0]);
            // its NPBG line: one with quantity left, the most alike description
            $lines = $outDoc && $outDoc !== '-' ? Npbg::where('no_npbg', $outDoc)->orderBy('accurate_seq')->get(['id', 'deskripsi_barang', 'kuantitas']) : collect();
            $line = $this->assignLine($lines, $desc, (float) ($this->num($ws->getCell("E$r")->getValue()) ?? 1), $used);
            // one NPBG line = one loan (unique): a second Excel row on the same line keeps only the number
            $lineId = $line && (! LendTransaction::where('out_npbg_id', $line->id)->whereKeyNot($l->id ?? 0)->exists()) ? $line->id : null;
            $est = $this->num($ws->getCell("I$r")->getValue());
            $purpose = strtoupper((string) $this->text($ws->getCell("H$r")->getValue()));
            $l->fill([
                'sheet_no' => $n,
                'out_date' => $date,
                'description_raw' => mb_substr($desc, 0, 400),
                'item_id' => $l->item_id ?? Item::whereRaw('UPPER(description) = ?', [mb_strtoupper($desc)])->value('id'),
                'qty' => $this->num($ws->getCell("E$r")->getValue()) ?? 1,
                'unit_raw' => $this->text($ws->getCell("F$r")->getValue()),
                'requester_name' => $this->text($ws->getCell("G$r")->getValue()),
                'purpose' => in_array($purpose, ['INTERNAL', 'PROJECT', 'RELASI'], true) ? $purpose : ($l->purpose ?? 'RELASI'),
                'est_days' => $est !== null ? (int) $est : null,
                'due_date' => $est ? Carbon::parse($date)->addDays((int) $est)->toDateString() : null,
                'out_doc_no' => $outDoc,
                'out_npbg_id' => $lineId,
                'condition_out' => ($k = $this->text($ws->getCell("K$r")->getValue())) ? mb_substr($k, 0, 255) : null,
                'return_doc_no' => $backDoc ? mb_substr($backDoc, 0, 200) : null,
                'return_date' => $this->date($ws->getCell("N$r")->getValue(), $backDoc),
                // a word instead of a date in "Tgl Kembali" ("LOST", "TIDAK JADI")
                'condition_in' => (fn ($c) => is_numeric($c) || $this->date($c) || ! $this->text($c) ? null : mb_substr($this->text($c), 0, 255))($ws->getCell("N$r")->getValue()),
                'return_note' => ($o = $this->text($ws->getCell("O$r")->getValue())) ? mb_substr($o, 0, 500) : null,
                'source' => 'EXCEL',
            ]);
            $l->save();
            $st['pictures'] += $this->picture($images, $outCell, 'lend', $l->id, 'out', $outDoc, $dryRun)
                + $this->picture($images, $backCell, 'lend', $l->id, 'in', $backDoc, $dryRun);

            $cached = $this->cached($ws->getCell("L$r"));
            $l->status = match ($cached) {
                'KEMBALI' => 'RETURNED', 'DEADLINE' => 'OVERDUE', 'SEDANG DIPINJAM' => 'ON_LOAN',
                default => $this->rules->lendStatus($l),
            };
            // "DEADLINE" is relative to today — recomputed for loans still out
            if ($l->status !== 'RETURNED' && $l->status !== 'INCOMPLETE') {
                $l->status = $this->rules->lendStatus($l) === 'OVERDUE' ? 'OVERDUE' : 'ON_LOAN';
            }
            $l->qty_returned = $l->status === 'RETURNED' ? $l->qty : 0;
            $l->save();
            $st[match ($l->status) { 'RETURNED' => 'KEMBALI', 'OVERDUE' => 'DEADLINE', 'ON_LOAN' => 'SEDANG DIPINJAM (lend)', default => 'kosong' }]++;
        }
    }

    /** Borrow: No | Tgl Pinjam | Deskripsi | Qty | Satuan | Vendor | Keterangan | Tanda Terima | Status | Tanda Keluar | Ket Barang Kembali */
    private function borrow(?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, WpsCellImages $images, bool $dryRun, array &$st): void
    {
        $docs = [];
        for ($r = 6; $ws && $r <= $ws->getHighestDataRow('D'); $r++) {
            $c = $ws->getCell("I$r")->getValue();
            if (! WpsCellImages::idOf($c) && ($no = $this->docNo($c)) && $no !== '-') {
                $docs[$no] = true;
            }
        }
        $st['borrow_replaced_accurate'] = $this->replaceAccurate(BorrowTransaction::class, 'in_ri_id', Ri::whereIn('no_ri', array_keys($docs))->pluck('id'), 'borrow');
        BorrowTransaction::where('source', 'EXCEL')->update(['in_ri_id' => null]);
        $used = [];
        $n = 0;
        for ($r = 6; $ws && $r <= $ws->getHighestDataRow('D'); $r++) {
            $desc = $this->text($ws->getCell("D$r")->getValue());
            if (! $desc) {
                continue;
            }
            $n++;
            $st['borrow_rows']++;
            $inCell = $ws->getCell("I$r")->getValue();
            $outCell = $ws->getCell("K$r")->getValue();
            $inDoc = WpsCellImages::idOf($inCell) ? null : $this->docNo($inCell);
            $outDoc = WpsCellImages::idOf($outCell) ? null : $this->text($outCell);
            $date = $this->date($ws->getCell("C$r")->getValue()) ?? now()->toDateString();

            $b = BorrowTransaction::where('source', 'EXCEL')->where('sheet_no', $n)->first()
                ?? new BorrowTransaction(['number' => $this->numbers->next('BORROW', 'SDA', Carbon::parse($date)), 'qty_returned' => 0]);
            $lines = $inDoc && $inDoc !== '-' ? Ri::where('no_ri', $inDoc)->orderBy('accurate_seq')->get(['id', 'deskripsi_barang', 'kuantitas']) : collect();
            $line = $this->assignLine($lines, $desc, (float) ($this->num($ws->getCell("E$r")->getValue()) ?? 1), $used);
            $lineId = $line && ! BorrowTransaction::where('in_ri_id', $line->id)->whereKeyNot($b->id ?? 0)->exists() ? $line->id : null;
            $b->fill([
                'sheet_no' => $n,
                'borrowed_at' => $date,
                'description_raw' => mb_substr($desc, 0, 400),
                'qty' => $this->num($ws->getCell("E$r")->getValue()) ?? 1,
                'unit_raw' => $this->text($ws->getCell("F$r")->getValue()),
                'lender_name' => $this->text($ws->getCell("G$r")->getValue()),
                'condition_note' => ($k = $this->text($ws->getCell("H$r")->getValue())) ? mb_substr($k, 0, 255) : null,
                'in_doc_no' => $inDoc,
                'receipt_ref' => $inDoc ? mb_substr($inDoc, 0, 60) : $b->receipt_ref,
                'in_ri_id' => $lineId,
                'out_doc_no' => $outDoc ? mb_substr($outDoc, 0, 200) : null,
                'return_note' => ($o = $this->text($ws->getCell("L$r")->getValue())) ? mb_substr($o, 0, 500) : null,
                'source' => 'EXCEL',
            ]);
            $b->save();
            $st['pictures'] += $this->picture($images, $inCell, 'borrow', $b->id, 'in', $inDoc, $dryRun)
                + $this->picture($images, $outCell, 'borrow', $b->id, 'out', $outDoc, $dryRun);
            $b->status = match ($this->cached($ws->getCell("J$r"))) {
                'LUNAS' => 'RETURNED', 'SEDANG DIPINJAM' => 'BORROWED', default => $this->rules->borrowStatus($b),
            };
            $b->qty_returned = $b->status === 'RETURNED' ? $b->qty : 0;
            $b->save();
            $st[match ($b->status) { 'RETURNED' => 'LUNAS', 'BORROWED' => 'SEDANG DIPINJAM (borrow)', default => 'kosong' }]++;
        }
    }

    /** Loans STOCKWISE derived from Accurate lines the Excel now records itself: removed (they have no typed data). */
    private function replaceAccurate(string $model, string $lineCol, \Illuminate\Support\Collection $lineIds, string $entity): int
    {
        $old = $model::where('source', LendBorrowFromAccurate::SOURCE)->whereIn($lineCol, $lineIds)->get();
        foreach ($old as $o) {
            foreach (\App\Models\TrackingPhoto::where(['entity' => $entity, 'entity_id' => $o->id])->get() as $ph) {
                $this->photos->delete($ph);
            }
            $o->delete();
        }

        return $old->count();
    }

    private function picture(WpsCellImages $images, mixed $cell, string $entity, int $id, string $slot, ?string $name, bool $dryRun): int
    {
        $img = $images->get(WpsCellImages::idOf($cell));
        if (! $img || $this->photos->has($entity, $id, $slot)) {
            return 0;
        }
        $dryRun || $this->photos->put($entity, $id, $slot, $img['bytes'], $img['ext'], $name ?? 'Tanda '.($slot === 'out' ? 'Keluar' : 'Kembali'), 'EXCEL');

        return 1;
    }
}
