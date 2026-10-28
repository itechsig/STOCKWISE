<?php

namespace App\Services\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use App\Models\TyreChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ban Luar tracking derived from Accurate on every sync (Excel "5. Tracking
 * Ban Luar" is the format reference only):
 *
 *   NPBG line, item "BAN LUAR", ship-to is a vehicle  → one tyre change per tyre
 *     ("PENGELUARAN BAN LUAR ORI U/ PENGGANTIAN BAN BAGIAN DEPAN KANAN … | NO SERI BAN LAMA: 5IA1030")
 *   RI (NV) line, item "BAN LUAR" (the old tyre coming back) → closes it
 *     ("PENGEMBALIAN BAN LUAR BEKAS … REF NPBG: NA/26/IX/054 | NO SERI BAN BEKAS: 16SDA09 (M-0723)")
 *
 * Serial numbers and position are read from the keterangan when written
 * there; anything typed by a person (serials, position, descriptions) is
 * never overwritten. Returns are matched via the quoted NPBG number, else the
 * same vehicle, oldest first.
 */
class TyreFromAccurate
{
    public const SOURCE = 'ACCURATE';

    private const NPBG_NO = '/\b(?:NA|ATK)\/\d{2}\/[IVX]+\/\d{3}\b/u';

    public static function position(?string $ket): ?string
    {
        if (preg_match('/\b(DEPAN|BELAKANG|TENGAH)\s+(KANAN|KIRI)(?:\s+(DALAM|LUAR))?/u', mb_strtoupper((string) $ket), $m)) {
            return mb_substr(trim($m[0]), 0, 20);
        }

        return null;
    }

    /** Serial after "NO SERI BAN <kind>:" in the keterangan (kinds e.g. BARU/VUL/ORI or LAMA/BEKAS). */
    public static function serial(?string $ket, array $kinds): ?string
    {
        $k = mb_strtoupper((string) $ket);
        $re = '/NO\.?\s*SERI\s+BAN\s+(?:'.implode('|', $kinds).')\s*:\s*([^|\r\n]+)/u';
        if (! preg_match($re, $k, $m)) {
            return null;
        }
        $v = trim(preg_split('/\s+&\s+|\s+SP\d/u', $m[1])[0]);
        $v = trim(preg_replace('/[\s\-]+$/u', '', $v));

        // "MENYUSUL" (to follow) / "-" / "TIDAK ADA" are placeholders, not serials
        return $v === '' || preg_match('/^(MENYUSUL|TIDAK\s+ADA|BELUM\s+ADA|N\/?A)\b/u', $v) ? null : mb_substr($v, 0, 80);
    }

    /** @return array{total:int,inserted:int,updated:int,skipped:int,errors:int} */
    public function derive(): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $fp = fn (TyreChange $t) => json_encode(collect($t->getAttributes())->except(['updated_at', 'created_at'])->map(fn ($v) => (string) $v)->sortKeys()->all());
        $before = TyreChange::where('source', self::SOURCE)->get()->mapWithKeys(fn ($t) => [$t->id => $fp($t)])->all();

        // ---- tyres out (an NPBG the Excel / a person already records is theirs — not derived again)
        $recorded = TyreChange::where('source', '<>', self::SOURCE)->whereNotNull('out_npbg_no')->pluck('out_npbg_no')->flip();
        $outs = Npbg::where('deskripsi_barang', 'like', '%BAN LUAR%')->orderBy('tgl_npbg')->orderBy('id')->get()
            ->filter(fn (Npbg $n) => AccurateDocRefs::isVehicle($n->pelanggan) && ! isset($recorded[$n->no_npbg]));
        $keep = [];
        foreach ($outs as $n) {
            for ($i = 1, $qty = max(1, (int) round((float) $n->kuantitas)); $i <= $qty; $i++) {
                $counts['total']++;
                $t = TyreChange::firstOrNew(['out_npbg_id' => $n->id, 'change_seq' => $i]);
                // this tyre was filled from the Excel (or by hand): it is theirs now
                if ($t->exists && $t->source !== self::SOURCE) {
                    $counts['skipped']++;

                    continue;
                }
                $t->fill([
                    'nopol_raw' => mb_substr((string) $n->pelanggan, 0, 120),
                    'change_date' => Carbon::parse($n->tgl_npbg ?? now())->toDateString(),
                    'out_note' => mb_substr((string) $n->keterangan, 0, 500) ?: null,
                    'source' => self::SOURCE,
                    'is_opening' => false,
                    // recomputed below from the RI side
                    'status' => 'PENDING_RI', 'in_ri_id' => null, 'in_ri_no' => null, 'in_date' => null, 'in_note' => null,
                ]);
                // filled from Accurate only while nobody typed them
                $t->new_tyre_desc ??= mb_substr((string) $n->deskripsi_barang, 0, 300);
                $t->position ??= self::position($n->keterangan);
                $t->new_serial_raw ??= self::serial($n->keterangan, ['BARU', 'VUL', 'VULKANISIR', 'ORI', 'ORIGINAL', 'BAN']);
                $t->old_serial_raw ??= self::serial($n->keterangan, ['LAMA', 'BEKAS']);
                $t->out_npbg_no = $n->no_npbg;
                $t->sheet_no ??= 1 + (int) TyreChange::max('sheet_no'); // next row of the "Ban Luar" sheet
                $t->save();
                $keep[] = $t->id;
            }
        }
        TyreChange::where('source', self::SOURCE)->whereNotIn('id', $keep)->delete();

        // ---- old tyres back
        $tyres = TyreChange::where('source', self::SOURCE)->with('outNpbg:id,no_npbg')->orderBy('change_date')->orderBy('id')->get();
        $returns = Ri::where('deskripsi_barang', 'like', '%BAN LUAR%')->where('divisi', 'NV')->orderBy('tgl_ri')->orderBy('id')->get();
        // RI lines the Excel rows already closed a tyre with are used up by them
        $usedByOthers = TyreChange::where('source', '<>', self::SOURCE)->whereNotNull('in_ri_id')
            ->selectRaw('in_ri_id, count(*) n')->groupBy('in_ri_id')->pluck('n', 'in_ri_id');
        foreach ($returns as $r) {
            $refs = preg_match_all(self::NPBG_NO, mb_strtoupper((string) $r->keterangan), $m) ? $m[0] : [];
            $day = $r->tgl_ri?->toDateString();
            $candidates = $tyres
                ->filter(fn (TyreChange $t) => $t->status === 'PENDING_RI')
                ->filter(fn (TyreChange $t) => ! $day || $t->change_date?->toDateString() <= $day)
                ->filter(fn (TyreChange $t) => in_array($t->outNpbg?->no_npbg, $refs, true)
                    || ($refs === [] && $this->sameVehicle($t->nopol_raw, $r->vendor)))
                ->sortBy(fn (TyreChange $t) => [$t->change_date?->toDateString(), $t->id]);

            $left = max(1, (int) round((float) $r->kuantitas)) - (int) ($usedByOthers[$r->id] ?? 0);
            foreach ($candidates->take(max(0, $left)) as $t) {
                $t->status = 'CLEAR';
                $t->in_ri_id = $r->id;
                $t->in_ri_no = $r->no_ri;
                $t->in_date = $day;
                $t->in_note = mb_substr((string) $r->keterangan, 0, 500) ?: null;
                $t->old_tyre_desc ??= mb_substr((string) $r->deskripsi_barang, 0, 300);
                $t->old_serial_raw ??= self::serial($r->keterangan, ['BEKAS', 'LAMA']);
            }
        }
        foreach ($tyres as $t) {
            $t->saveQuietly();
            if (! isset($before[$t->id])) {
                $counts['inserted']++;
            } elseif ($before[$t->id] !== $fp($t->fresh())) {
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    private function sameVehicle(?string $a, ?string $b): bool
    {
        $plate = fn ($s) => preg_match('/^([A-Z]{1,2}) ?(\d{1,4}) ?([A-Z]{0,3})/u', mb_strtoupper(trim((string) $s)), $m) ? $m[1].$m[2].$m[3] : mb_strtoupper(trim((string) $s));

        return $a !== null && $b !== null && $plate($a) === $plate($b);
    }
}
