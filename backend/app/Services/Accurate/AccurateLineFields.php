<?php

namespace App\Services\Accurate;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NPBG / RI line fields whose meaning changed in Accurate in September 2026
 * (user confirmed 2026-10-05):
 *
 *   old format  NPBG ITEMRESERVED1 = requester, RI ITEMRESERVED3 = checker,
 *               RI ITEMRESERVED1/2 = dates
 *   new format  requester / checker = the line's Department (DEPTID; a sub-
 *               department's DEPTNO is the person, e.g. NINING), ITEMRESERVED1/2
 *               = warehouse / rack ("GUDANG 1" / "RAK B.30"), NPBG SHIPVIA =
 *               jenis pengeluaran (SHIPMENT.NAME)
 *
 * A document is "new format" when any of its lines has a DEPTID or its header a
 * SHIPVIA. Users often fill the Department on the first line only — the other
 * lines take the document's first one. An RI whose ITEMRESERVED1 is not a date
 * holds a location even without a Department (RI/NV/26/X/003).
 */
class AccurateLineFields
{
    /** @var array<int, string> DEPTID => person (sub-department DEPTNO) or department name */
    private array $people = [];

    /** @var array<int, string> SHIPID => name */
    private array $shipments = [];

    /** @var array<string, array{new: bool, dept: ?int}> */
    private array $docs = [];

    /** @var array<int, string>|null ITEMHISTID => SNLine; null when the derived table is not staged */
    private ?array $serials = null;

    /** @var array<int, ?string>|null TERMID => TERMMEMO; null when TERMOPMT is not staged */
    private ?array $terms = null;

    public function __construct()
    {
        if (Schema::hasTable('accurate_department')) {
            $sub = Schema::hasColumn('accurate_department', 'ISSUBDEPT');
            foreach (DB::table('accurate_department')->get() as $d) {
                $no = trim((string) ($d->DEPTNO ?? ''));
                $name = trim((string) ($d->DEPTNAME ?? ''));
                $isSub = $sub ? (int) ($d->ISSUBDEPT ?? 0) === 1 : ! ctype_digit($no);
                $this->people[(int) $d->DEPTID] = ($isSub && $no !== '') ? $no : ($name !== '' ? $name : $no);
            }
        }
        if (Schema::hasTable('accurate_snline_byitemhist')) {
            $this->serials = DB::table('accurate_snline_byitemhist')->pluck('SNLINE', 'ITEMHISTID')
                ->mapWithKeys(fn ($l, $id) => [(int) $id => (string) $l])->all();
        }
        if (Schema::hasTable('accurate_termopmt')) {
            $this->terms = DB::table('accurate_termopmt')->pluck('TERMMEMO', 'TERMID')
                ->mapWithKeys(fn ($m, $id) => [(int) $id => $m !== null ? trim((string) $m) : null])->all();
        }
        if (Schema::hasTable('accurate_shipment')) {
            $this->shipments = DB::table('accurate_shipment')->pluck('NAME', 'SHIPID')
                ->mapWithKeys(fn ($n, $id) => [(int) $id => trim((string) $n)])->all();
        }
    }

    /** Remember, per document, whether it uses the new format and its first Department. */
    public function prepare(Collection $rows, string $docKey): void
    {
        $this->docs = [];
        foreach ($rows->groupBy($docKey) as $doc => $lines) {
            $dept = $lines->first(fn ($l) => ! empty($l->DEPTID))?->DEPTID;
            $this->docs[(string) $doc] = [
                'new' => $dept !== null || $lines->contains(fn ($l) => ! empty($l->SHIPVIA ?? null)),
                'dept' => $dept !== null ? (int) $dept : null,
            ];
        }
    }

    public function isNewFormat(object $row, string $docKey): bool
    {
        return $this->docs[(string) $row->{$docKey}]['new'] ?? false;
    }

    /** Requester (NPBG) / checker (RI): the Department in the new format, else the old field. */
    public function person(object $row, string $docKey, string $legacyField): ?string
    {
        if ($this->isNewFormat($row, $docKey)) {
            $dept = ! empty($row->DEPTID) ? (int) $row->DEPTID : $this->docs[(string) $row->{$docKey}]['dept'];

            return $dept !== null ? ($this->people[$dept] ?? null) : null;
        }

        return $this->text($row->{$legacyField} ?? null);
    }

    /** @return array{0: ?string, 1: ?string} warehouse, rack */
    public function location(object $row, string $docKey, bool $isRi): array
    {
        $r1 = $this->text($row->ITEMRESERVED1 ?? null);
        $r2 = $this->text($row->ITEMRESERVED2 ?? null);
        $holdsLocation = $this->isNewFormat($row, $docKey)
            || ($isRi && $r1 !== null && ! preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $r1));

        // columns are 60 wide
        return $holdsLocation ? [$r1 !== null ? mb_substr($r1, 0, 60) : null, $r2 !== null ? mb_substr($r2, 0, 60) : null] : [null, null];
    }

    /** SHIPMENT name of a SHIPVIA — NPBG "Klasifikasi [Ship Via]", RI "Pengirim [Ship Via]". */
    public function shipment(mixed $shipVia): ?string
    {
        return ! empty($shipVia) ? ($this->shipments[(int) $shipVia] ?? null) : null;
    }

    /** False when SHIPMENT was not staged — the caller then keeps what it has. */
    public function hasShipments(): bool
    {
        return $this->shipments !== [];
    }

    /** No. Seri [SNLine] of an ITEMHIST row, as Accurate's VIEW_SN_BYITEMHIST printed it (agent-computed). */
    public function serial(mixed $itemHistId): ?string
    {
        return $itemHistId !== null ? ($this->serials[(int) $itemHistId] ?? null) : null;
    }

    /**
     * NPBG "Tipe NPBG [Terms]": ARINV.TERMSID → TERMOPMT.TERMMEMO as the NPBG print shows it —
     * "PENJUALAN" → Penjualan, "NON-PENJUALAN" → Non Penjualan (TERMNAME is C.O.D / Net 30).
     */
    public function npbgType(mixed $termsId): ?string
    {
        $memo = ! empty($termsId) ? ($this->terms[(int) $termsId] ?? null) : null;
        if ($memo === null || $memo === '') {
            return null;
        }
        $key = strtoupper(preg_replace('/[\s_-]+/', '', $memo));

        return match ($key) {
            'PENJUALAN' => 'Penjualan',
            'NONPENJUALAN' => 'Non Penjualan',
            default => mb_substr(mb_convert_case(mb_strtolower($memo), MB_CASE_TITLE), 0, 30),
        };
    }

    /** False when TERMOPMT was not staged — the caller then keeps what it has. */
    public function hasTerms(): bool
    {
        return $this->terms !== null;
    }

    /** False when SNLINE_BYITEMHIST was not staged — the caller then keeps what it has. */
    public function hasSerials(): bool
    {
        return $this->serials !== null;
    }

    private function text(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : mb_substr($v, 0, 200);
    }
}
