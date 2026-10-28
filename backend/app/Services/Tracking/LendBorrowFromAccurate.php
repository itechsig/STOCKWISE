<?php

namespace App\Services\Tracking;

use App\Models\BorrowTransaction;
use App\Models\Item;
use App\Models\LendTransaction;
use App\Models\Npbg;
use App\Models\Ri;
use App\Models\Unit;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lend / Borrow derived from Accurate on every sync — no admin action (user
 * decision 2026-09-29). Accurate has no loan document; loans are recognised
 * from the free-text keterangan of NPBG (goods out) and RI (goods in). Rules
 * were built from the real keterangan in the data:
 *
 *   NPBG + "PINJAM", not a return   → LEND out   ("UNTUK DIPINJAMKAN KE CDO", "CDO PINJAM UNTUK PEMAKAIAN")
 *   NPBG + "PINJAM" + a return word → BORROW returned ("UNTUK MENGEMBALIKAN PINJAMAN DARI CDO")
 *   RI   + "PINJAM" + a return word → LEND returned   ("PENGEMBALIAN PINJAMAN DARI CDO … REF NPBG: NA/26/IV/268",
 *                                                      "KEMBALI SETELAH DIPINJAMKAN", "TIDAK JADI DIPINJAMKAN")
 *   RI   + "PINJAM(AN) … DARI" / "PINJAM KE", not a return → BORROW in ("BARANG PINJAMAN DARI PT CIPTA ABADI")
 *
 * A return is matched to its loan by the document number quoted in the
 * keterangan ("REF NPBG: …" / "REF RI: …") plus the same item; otherwise to the
 * oldest still-open loan of the same item dated on/before the return.
 * Everything is recomputed from scratch each run (idempotent); MANUAL rows are
 * never touched. Rows whose source line disappeared are removed (FK cascade
 * for Lend/Borrow themselves, recomputation for the returns).
 */
class LendBorrowFromAccurate
{
    public const SOURCE = 'ACCURATE';

    private const RETURN_WORDS = '/MENGEMBALIKAN|KEMBALIKAN\s+PINJAMAN|PENGEMBALIAN|KEMBALI\s+SETELAH|TIDAK\s+JADI|BATAL/u';

    private const NPBG_NO = '/\b(?:NA|ATK)\/\d{2}\/[IVX]+\/\d{3}\b/u';

    private const RI_NO = '/\bRI\/[A-Z]+\/\d{2}\/[IVX]+\/\d{3}\b/u';

    public function __construct(private readonly DocumentNumberService $numbers) {}

    public static function isLendOut(?string $ket): bool
    {
        $k = mb_strtoupper((string) $ket);

        return str_contains($k, 'PINJAM') && ! preg_match(self::RETURN_WORDS, $k);
    }

    public static function isBorrowReturn(?string $ket): bool
    {
        $k = mb_strtoupper((string) $ket);

        return str_contains($k, 'PINJAM') && (bool) preg_match('/MENGEMBALIKAN|KEMBALIKAN\s+PINJAMAN|PENGEMBALIAN\s+(?:BARANG\s+)?PINJAMAN/u', $k);
    }

    public static function isLendReturn(?string $ket): bool
    {
        $k = mb_strtoupper((string) $ket);

        return str_contains($k, 'PINJAM') && (bool) preg_match(self::RETURN_WORDS, $k);
    }

    public static function isBorrowIn(?string $ket): bool
    {
        $k = mb_strtoupper((string) $ket);

        return ! preg_match(self::RETURN_WORDS, $k)
            && (bool) preg_match('/PINJAM(?:AN)?\b.*\bDARI\b|\bPINJAM\s+KE\b|DIPINJAMI/u', $k);
    }

    /** @return array{total:int,inserted:int,updated:int,skipped:int,errors:int} */
    public function derive(): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $itemIds = Item::pluck('id', 'code');
        $unitIds = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));

        // ---------------- LEND: out via NPBG, back via RI
        // an NPBG the Excel / a person already records as a loan is theirs — not derived again
        $recorded = self::usedDocs(LendTransaction::where('source', '<>', self::SOURCE)->pluck('out_doc_no'));
        $outLines = Npbg::where('keterangan', 'like', '%PINJAM%')->orderBy('tgl_npbg')->orderBy('id')->get()
            ->filter(fn (Npbg $n) => self::isLendOut($n->keterangan) && ! isset($recorded[$n->no_npbg]));
        $before = LendTransaction::where('source', self::SOURCE)->get()->mapWithKeys(fn ($l) => [$l->id => self::fingerprint($l)])->all();
        $keep = [];
        foreach ($outLines as $n) {
            $counts['total']++;
            $est = preg_match('/ESTIMASI\s*(\d{1,3})\s*HARI/u', mb_strtoupper((string) $n->keterangan), $m) ? (int) $m[1] : null;
            $date = Carbon::parse($n->tgl_npbg ?? now());
            $lend = LendTransaction::firstOrNew(['out_npbg_id' => $n->id]);
            // filled from the Excel (or by hand): theirs now, a sync leaves it alone
            if ($lend->exists && $lend->source !== self::SOURCE) {
                $counts['skipped']++;

                continue;
            }
            $lend->fill([
                'number' => $lend->number ?: $this->numbers->next('LEND', 'SDA', $date),
                'item_id' => $n->kode_barang ? $itemIds->get($n->kode_barang) : null,
                'description_raw' => mb_substr($n->deskripsi_barang ?: '-', 0, 400),
                'qty' => (float) ($n->kuantitas ?: 1),
                'unit_id' => $n->satuan ? $unitIds->get(mb_strtoupper(trim($n->satuan))) : null,
                'borrower_name' => mb_substr((string) ($n->pelanggan ?: $n->divisi), 0, 200) ?: null,
                'requester_name' => mb_substr((string) $n->peminta, 0, 100) ?: null,   // Excel "Peminta"
                'out_date' => $date->toDateString(),
                'out_doc_no' => $n->no_npbg,
                'unit_raw' => $n->satuan,
                'condition_out' => mb_substr((string) $n->keterangan, 0, 255) ?: null,
                'source' => self::SOURCE,
                // returns are recomputed below
                'qty_returned' => 0, 'return_date' => null, 'return_refs' => null, 'return_note' => null, 'status' => 'ON_LOAN',
            ]);
            // Keperluan / Est. pinjam: from Accurate when written there, else whatever
            // a person filled in on STOCKWISE (never overwritten by a sync).
            $lend->purpose = $lend->exists ? $lend->purpose : $this->purpose($n->pelanggan, $n->keterangan);
            $lend->est_days = $est ?? $lend->est_days;
            $lend->due_date = $lend->est_days ? $date->copy()->addDays((int) $lend->est_days)->toDateString() : null;
            $lend->sheet_no ??= 1 + (int) LendTransaction::max('sheet_no'); // next row of the "Lend" sheet
            $lend->save();
            $keep[] = $lend->id;
        }
        LendTransaction::where('source', self::SOURCE)->whereNotIn('id', $keep)->delete();

        $lends = LendTransaction::where('source', self::SOURCE)->with('outNpbg:id,no_npbg')->orderBy('out_date')->orderBy('id')->get();
        // RIs the Excel rows already returned a loan with are theirs
        $usedRi = self::usedDocs(LendTransaction::where('source', '<>', self::SOURCE)->pluck('return_doc_no'));
        $returns = Ri::where('keterangan', 'like', '%PINJAM%')->orderBy('tgl_ri')->orderBy('id')->get()
            ->filter(fn (Ri $r) => self::isLendReturn($r->keterangan) && ! isset($usedRi[$r->no_ri]));
        foreach ($returns as $r) {
            $refs = preg_match_all(self::NPBG_NO, mb_strtoupper((string) $r->keterangan), $m) ? $m[0] : [];
            $this->allocate($lends, $r->kode_barang, (float) $r->kuantitas, $r->tgl_ri, $r->no_ri, $r->vendor,
                fn (LendTransaction $l) => $l->outNpbg?->no_npbg, $refs, 'out_date', $r->keterangan);
        }
        foreach ($lends as $l) {
            $l->status = $l->qty_returned >= $l->qty - 1e-6 ? 'RETURNED' : ($l->qty_returned > 0 ? 'PARTIAL_RETURN' : 'ON_LOAN');
            $l->saveQuietly();
        }
        self::tally($lends, $before, $counts);

        // ---------------- BORROW: in via RI, back via NPBG
        $recorded = self::usedDocs(BorrowTransaction::where('source', '<>', self::SOURCE)->pluck('in_doc_no'));
        $inLines = Ri::where('keterangan', 'like', '%PINJAM%')->orderBy('tgl_ri')->orderBy('id')->get()
            ->filter(fn (Ri $r) => self::isBorrowIn($r->keterangan) && ! isset($recorded[$r->no_ri]));
        $before = BorrowTransaction::where('source', self::SOURCE)->get()->mapWithKeys(fn ($b) => [$b->id => self::fingerprint($b)])->all();
        $keep = [];
        foreach ($inLines as $r) {
            $counts['total']++;
            $date = Carbon::parse($r->tgl_ri ?? now());
            $borrow = BorrowTransaction::firstOrNew(['in_ri_id' => $r->id]);
            if ($borrow->exists && $borrow->source !== self::SOURCE) {
                $counts['skipped']++;

                continue;
            }
            $borrow->fill([
                'number' => $borrow->number ?: $this->numbers->next('BORROW', 'SDA', $date),
                'item_id' => $r->kode_barang ? $itemIds->get($r->kode_barang) : null,
                'description_raw' => mb_substr($r->deskripsi_barang ?: '-', 0, 400),
                'qty' => (float) ($r->kuantitas ?: 1),
                'unit_id' => $r->satuan ? $unitIds->get(mb_strtoupper(trim($r->satuan))) : null,
                'lender_name' => mb_substr((string) $r->vendor, 0, 200) ?: null,
                'receipt_ref' => mb_substr((string) $r->no_ri, 0, 60) ?: null,
                'in_doc_no' => $r->no_ri,
                'unit_raw' => $r->satuan,
                'borrowed_at' => $date->toDateString(),
                'condition_note' => mb_substr((string) $r->keterangan, 0, 255) ?: null,
                'source' => self::SOURCE,
                'qty_returned' => 0, 'returned_at' => null, 'return_refs' => null, 'return_note' => null, 'status' => 'BORROWED',
            ]);
            $borrow->sheet_no ??= 1 + (int) BorrowTransaction::max('sheet_no'); // next row of the "Borrow" sheet
            $borrow->save();
            $keep[] = $borrow->id;
        }
        BorrowTransaction::where('source', self::SOURCE)->whereNotIn('id', $keep)->delete();

        $borrows = BorrowTransaction::where('source', self::SOURCE)->orderBy('borrowed_at')->orderBy('id')->get();
        $usedNpbg = self::usedDocs(BorrowTransaction::where('source', '<>', self::SOURCE)->pluck('out_doc_no'));
        $returns = Npbg::where('keterangan', 'like', '%PINJAM%')->orderBy('tgl_npbg')->orderBy('id')->get()
            ->filter(fn (Npbg $n) => self::isBorrowReturn($n->keterangan) && ! isset($usedNpbg[$n->no_npbg]));
        foreach ($returns as $n) {
            $refs = preg_match_all(self::RI_NO, mb_strtoupper((string) $n->keterangan), $m) ? $m[0] : [];
            $this->allocate($borrows, $n->kode_barang, (float) $n->kuantitas, $n->tgl_npbg, $n->no_npbg, $n->pelanggan,
                fn (BorrowTransaction $b) => $b->receipt_ref, $refs, 'borrowed_at', $n->keterangan);
        }
        foreach ($borrows as $b) {
            $b->status = $b->qty_returned >= $b->qty - 1e-6 ? 'RETURNED' : ($b->qty_returned > 0 ? 'PARTIAL' : 'BORROWED');
            $b->saveQuietly();
        }
        self::tally($borrows, $before, $counts);

        return $counts;
    }

    /**
     * Spread one return line over the open loans of the same item: first the
     * loans whose document the keterangan quotes, then the same counterparty,
     * then the oldest — never a loan dated after the return.
     *
     * @param  Collection<int, LendTransaction|BorrowTransaction>  $loans
     * @param  list<string>  $refs
     */
    private function allocate(Collection $loans, ?string $code, float $qty, $date, ?string $docNo, ?string $party, callable $loanDocNo, array $refs, string $dateField, ?string $note = null): void
    {
        if (! $code || $qty <= 0) {
            return;
        }
        $day = $date ? Carbon::parse($date)->toDateString() : null;
        $item = Item::where('code', $code)->value('id');
        $party = $this->norm($party);

        $candidates = $loans
            ->filter(fn ($l) => $l->item_id === $item && $item !== null)
            ->filter(fn ($l) => ! $day || $l->{$dateField}?->toDateString() <= $day)
            ->filter(fn ($l) => $l->qty_returned < $l->qty - 1e-6)
            ->sortBy(fn ($l) => [
                in_array($loanDocNo($l), $refs, true) ? 0 : 1,
                $party !== '' && $this->norm($l->borrower_name ?? $l->lender_name) === $party ? 0 : 1,
                $l->{$dateField}?->toDateString(),
                $l->id,
            ]);

        foreach ($candidates as $loan) {
            if ($qty <= 1e-6) {
                break;
            }
            $take = min($qty, $loan->qty - $loan->qty_returned);
            $loan->qty_returned += $take;
            $qty -= $take;
            $refsField = 'return_refs';
            $loan->{$refsField} = mb_substr(trim(implode(', ', array_unique(array_filter([...explode(', ', (string) $loan->{$refsField}), $docNo])))), 0, 500);
            $loan->return_note = mb_substr((string) $note, 0, 500) ?: $loan->return_note;
            if ($loan instanceof LendTransaction) {
                $loan->return_date = $day;
            } else {
                $loan->returned_at = $day;
            }
        }
    }

    /** Document numbers written in Excel/hand-made rows ("RI/NV/26/I/062, RI/NV/26/I/070"), as a set. */
    private static function usedDocs(Collection $texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            preg_match_all('/\b(?:RI\/[A-Z]+|NA|ATK)\/\d{2}\/[IVX]+\/\d{3}\b/u', mb_strtoupper((string) $t), $m);
            foreach ($m[0] as $no) {
                $out[$no] = true;
            }
        }

        return $out;
    }

    /** What a user sees of a loan — to tell a real change from a no-op rerun. */
    private static function fingerprint(LendTransaction|BorrowTransaction $l): string
    {
        return json_encode(collect($l->getAttributes())->except(['updated_at', 'created_at'])->map(fn ($v) => (string) $v)->sortKeys()->all());
    }

    /** @param array<int, string> $before */
    private static function tally(Collection $loans, array $before, array &$counts): void
    {
        foreach ($loans as $l) {
            if (! isset($before[$l->id])) {
                $counts['inserted']++;
            } elseif ($before[$l->id] !== self::fingerprint($l->fresh())) {
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
        }
    }

    private function norm(?string $name): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', preg_replace('/\b(PT|CV|TBK)\b\.?/u', '', mb_strtoupper((string) $name))));
    }

    /** Internal division (e.g. "DISTRIBUSI (SDA)") vs an outside party. */
    private function purpose(?string $party, ?string $ket): string
    {
        $p = mb_strtoupper((string) $party);
        if (str_contains(mb_strtoupper((string) $ket), 'PROJECT') || str_contains(mb_strtoupper((string) $ket), 'PROYEK')) {
            return 'PROJECT';
        }

        return preg_match('/\((SDA|BPP|BPN)\)|^(ACCOUNTING|MAINTENANCE|GUDANG|DISTRIBUSI|MARKETING|INVENTORY|ADMIN|SALES)/u', $p) ? 'INTERNAL' : 'RELASI';
    }
}
