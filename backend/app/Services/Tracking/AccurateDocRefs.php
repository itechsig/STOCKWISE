<?php

namespace App\Services\Tracking;

use App\Models\Npbg;
use App\Models\Ri;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * NPBG / RI numbers typed on the tracking forms exactly as in the Excel
 * ("NA/25/VIII/009", "RI/NV/25/VIII/019") resolved against the Accurate
 * mirrors, plus the vehicle (nopol) list those documents already contain.
 */
class AccurateDocRefs
{
    /** Vehicle-looking "ship to": a plate (W 8985 QF …) or a trailer. */
    public const VEHICLE_REGEX = '^([A-Z]{1,2} ?[0-9]{1,4} ?[A-Z]{0,3}( |\\(|$)|EKOR TRAILER|TRAILER )';

    public static function normalizeNo(?string $no): ?string
    {
        $no = strtoupper(trim(preg_replace('/\s+/', '', (string) $no)));

        return $no === '' ? null : $no;
    }

    /** First NPBG line id of that number, or a validation error on $field. */
    public function npbgId(?string $no, string $field = 'npbg_no'): ?int
    {
        if (! $no = self::normalizeNo($no)) {
            return null;
        }
        $id = Npbg::where('no_npbg', $no)->orderBy('id')->value('id');
        if (! $id) {
            throw ValidationException::withMessages([$field => ["No. NPBG {$no} tidak ditemukan di data Accurate."]]);
        }

        return $id;
    }

    public function riId(?string $no, string $field = 'ri_no'): ?int
    {
        if (! $no = self::normalizeNo($no)) {
            return null;
        }
        $id = Ri::where('no_ri', $no)->orderBy('id')->value('id');
        if (! $id) {
            throw ValidationException::withMessages([$field => ["No. RI {$no} tidak ditemukan di data Accurate."]]);
        }

        return $id;
    }

    /** @return array{number:string,date:?string,party:?string,note:?string,lines:list<array{code:?string,description:?string,qty:float,unit:?string}>}|null */
    public function npbgSummary(?string $no): ?array
    {
        $lines = Npbg::where('no_npbg', self::normalizeNo($no))->orderBy('accurate_seq')->get();

        return $lines->isEmpty() ? null : [
            'number' => $lines[0]->no_npbg,
            'date' => $lines[0]->tgl_npbg?->toDateString(),
            'party' => $lines[0]->pelanggan,
            'division' => $lines[0]->divisi,
            'note' => $lines[0]->keterangan,
            'lines' => $this->lines($lines),
        ];
    }

    public function riSummary(?string $no): ?array
    {
        $lines = Ri::where('no_ri', self::normalizeNo($no))->orderBy('accurate_seq')->get();

        return $lines->isEmpty() ? null : [
            'number' => $lines[0]->no_ri,
            'date' => $lines[0]->tgl_ri?->toDateString(),
            'party' => $lines[0]->vendor,
            'note' => $lines[0]->keterangan,
            'lines' => $this->lines($lines),
        ];
    }

    /**
     * NPBG / RI documents to pick from while typing: matches the number (spaces
     * ignored), keterangan, customer/vendor or an item description; newest first.
     *
     * @return list<array{number:string,date:?string,party:?string,note:?string,lines:list<array>}>
     */
    public function search(string $type, ?string $q, int $limit = 20): array
    {
        [$model, $no, $date, $party] = $type === 'ri'
            ? [Ri::class, 'no_ri', 'tgl_ri', 'vendor']
            : [Npbg::class, 'no_npbg', 'tgl_npbg', 'pelanggan'];
        $q = trim((string) $q);
        $compact = strtoupper(preg_replace('/\s+/', '', $q));
        // every word must appear somewhere (number, keterangan, customer/vendor, item)
        $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);

        $numbers = $model::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where($no, 'like', "%{$compact}%")
                ->orWhere(function ($w) use ($words, $party) {
                    foreach ($words as $word) {
                        $w->where(fn ($x) => $x->where('keterangan', 'like', "%{$word}%")
                            ->orWhere($party, 'like', "%{$word}%")
                            ->orWhere('deskripsi_barang', 'like', "%{$word}%"));
                    }
                })))
            ->selectRaw("{$no} as n, max({$date}) as d, max(id) as i")
            ->groupBy($no)->orderByDesc('d')->orderByDesc('i')->limit($limit)->pluck('n');
        if ($numbers->isEmpty()) {
            return [];
        }
        $rows = $model::query()->whereIn($no, $numbers)->orderBy('accurate_seq')->get()->groupBy($no);

        return $numbers->map(function ($n) use ($rows, $date, $party) {
            $lines = $rows[$n];

            return [
                'number' => $n,
                'date' => $lines[0]->{$date}?->toDateString(),
                'party' => $lines[0]->{$party},
                'note' => $lines[0]->keterangan,
                'serials' => \App\Support\ManufacturingExcel::serials($lines[0]->keterangan),
                'lines' => $this->lines($lines),
            ];
        })->values()->all();
    }

    /** Distinct vehicles seen on NPBG "ship to" / RI vendor, most used first. */
    public function vehicles(?string $search = null, int $limit = 30): Collection
    {
        $s = strtoupper(trim((string) $search));
        $fromNpbg = Npbg::query()->whereNotNull('pelanggan')
            ->when($s !== '', fn ($q) => $q->where('pelanggan', 'like', "%{$s}%"))
            ->selectRaw('pelanggan as name, count(*) n')->groupBy('pelanggan')->get();
        $fromRi = Ri::query()->whereNotNull('vendor')
            ->when($s !== '', fn ($q) => $q->where('vendor', 'like', "%{$s}%"))
            ->selectRaw('vendor as name, count(*) n')->groupBy('vendor')->get();

        return $fromNpbg->concat($fromRi)
            ->filter(fn ($r) => self::isVehicle($r->name))
            ->groupBy('name')->map(fn ($g, $name) => ['name' => $name, 'uses' => (int) $g->sum('n')])
            ->sortByDesc('uses')->values()->take($limit);
    }

    public static function isVehicle(?string $name): bool
    {
        return (bool) preg_match('/'.self::VEHICLE_REGEX.'/u', strtoupper(trim((string) $name)));
    }

    private function lines(Collection $lines): array
    {
        return $lines->map(fn ($l) => [
            'code' => $l->kode_barang, 'description' => $l->deskripsi_barang,
            'qty' => (float) $l->kuantitas, 'unit' => $l->satuan,
        ])->values()->all();
    }
}
