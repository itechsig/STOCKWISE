<?php

namespace App\Services\Tracking;

use App\Models\TyreChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Ban Luar = the Excel "5. Tracking Ban Luar" (sheet "Ban Luar"). Rows come from
 * the Excel (source EXCEL), from Accurate (TyreFromAccurate: NPBG out → RI NV back)
 * or by hand (MANUAL). Status = the Excel formula: CLEAR (NPBG + seri baru + RI +
 * seri lama) / PENDING RI (NPBG + seri baru). Foto Ban Out / In = tracking_photos.
 */
class TyreChangeService
{
    /** Excel columns a user types. */
    public const FIELDS = ['nopol_raw', 'change_date', 'npbg_no', 'new_tyre_desc', 'new_serial_raw', 'new_file_name', 'position', 'tyre_no',
        'replacement_no', 'reason', 'out_note', 'in_date', 'ri_no', 'old_tyre_desc', 'old_serial_raw', 'old_file_name', 'in_note'];

    /** What a person may still complete on a row that comes from Accurate. */
    private const ACCURATE_EDITABLE = ['position', 'tyre_no', 'replacement_no', 'new_tyre_desc', 'new_serial_raw', 'new_file_name',
        'old_tyre_desc', 'old_serial_raw', 'old_file_name'];

    public function __construct(private readonly AccurateDocRefs $refs) {}

    public function record(User $user, array $data): TyreChange
    {
        $opening = (bool) ($data['is_opening'] ?? false);
        $t = new TyreChange([
            'asset_id' => $data['asset_id'] ?? null,
            'sheet_no' => 1 + (int) TyreChange::max('sheet_no'),
            'change_date' => Carbon::parse($data['change_date'] ?? now())->toDateString(),
            'is_opening' => $opening,
            'source' => 'MANUAL',
            'created_by' => $user->id,
        ]);
        $this->fill($t, $data + ($opening ? ['npbg_no' => 'ORIGIN'] : []));
        // an NPBG line takes one slot per tyre; without one, the n-th change of that vehicle + position
        $t->change_seq = $t->out_npbg_id
            ? 1 + (int) TyreChange::where('out_npbg_id', $t->out_npbg_id)->max('change_seq')
            : 1 + TyreChange::query()->whereNull('out_npbg_id')
                ->when($t->asset_id, fn ($q) => $q->where('asset_id', $t->asset_id), fn ($q) => $q->where('nopol_raw', $t->nopol_raw))
                ->where('position', $t->position)->count();
        $back = (trim((string) $t->in_ri_no) !== '' && $t->in_ri_no !== '-') || $t->in_date;
        $t->status = $opening || $back ? 'CLEAR' : 'PENDING_RI';
        if ($opening) {
            $t->in_date ??= $t->change_date;
        }
        $t->save();

        return $t->refresh();
    }

    /** The old tyre is back: No. RI (+ seri / deskripsi lama). */
    public function close(TyreChange $change, array $data): TyreChange
    {
        if ($change->source === TyreFromAccurate::SOURCE) {
            throw ValidationException::withMessages(['status' => ['Ban dari Accurate otomatis CLEAR saat RI ban bekasnya masuk di Accurate.']]);
        }
        if ($change->status === 'CLEAR') {
            throw ValidationException::withMessages(['status' => ['Penggantian ban sudah CLEAR.']]);
        }
        $this->fill($change, $data + ['in_date' => $data['in_date'] ?? now()->toDateString()]);
        $change->status = 'CLEAR';
        $change->save();

        return $change->refresh();
    }

    /** Rows from the Excel / by hand: every column. Rows from Accurate: only what Accurate does not know. */
    public function update(TyreChange $change, array $data): TyreChange
    {
        $fromAccurate = $change->source === TyreFromAccurate::SOURCE;
        $this->fill($change, $fromAccurate ? array_intersect_key($data, array_flip(self::ACCURATE_EDITABLE)) : $data);
        // rows from the Excel keep following its formula; a hand-made row is CLEAR once the old tyre is back
        // (No. RI / Tgl RI typed into the table, like STPP's Tanda Kembali) and PENDING RI again when cleared
        if ($change->source === 'EXCEL' && ! $change->is_opening) {
            $change->status = TyreChange::excelStatus($change->out_npbg_no, $change->new_serial_raw, $change->in_ri_no, $change->old_serial_raw);
        } elseif ($change->source === 'MANUAL' && ! $change->is_opening) {
            $back = (trim((string) $change->in_ri_no) !== '' && $change->in_ri_no !== '-') || $change->in_date;
            $change->status = $back ? 'CLEAR' : 'PENDING_RI';
        }
        $change->save();

        return $change->refresh();
    }

    public function delete(TyreChange $change): void
    {
        if ($change->source === TyreFromAccurate::SOURCE) {
            throw ValidationException::withMessages(['status' => ['Data ban dari Accurate tidak bisa dihapus di sini.']]);
        }
        if ($change->status === 'CLEAR') {
            throw ValidationException::withMessages(['status' => ['Penggantian ban yang sudah CLEAR tidak bisa dihapus.']]);
        }
        foreach (\App\Models\TrackingPhoto::where(['entity' => 'tyre', 'entity_id' => $change->id])->get() as $p) {
            app(TrackingPhotoService::class)->delete($p);
        }
        $change->delete();
    }

    private function fill(TyreChange $t, array $data): void
    {
        foreach (self::FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                continue;
            }
            $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
            $v = $v === '' ? null : $v;
            match ($f) {
                'npbg_no' => $t->fill(in_array(strtoupper((string) $v), ['', '-', 'ORIGIN'], true)
                    ? ['out_npbg_no' => $v ? strtoupper($v) : null, 'out_npbg_id' => null]
                    : ['out_npbg_no' => AccurateDocRefs::normalizeNo($v), 'out_npbg_id' => $this->refs->npbgId($v)]),
                'ri_no' => $t->fill(in_array(strtoupper((string) $v), ['', '-'], true)
                    ? ['in_ri_no' => $v, 'in_ri_id' => null]
                    : ['in_ri_no' => AccurateDocRefs::normalizeNo($v), 'in_ri_id' => $this->refs->riId($v)]),
                'nopol_raw' => $t->nopol_raw = $v ? mb_strtoupper($v) : null,
                // Tgl NPBG is required — an emptied cell keeps the date
                'change_date' => $t->change_date = $v ? Carbon::parse($v)->toDateString() : ($t->change_date ?? now()->toDateString()),
                'in_date' => $t->in_date = $v ? Carbon::parse($v)->toDateString() : null,
                default => $t->{$f} = $v,
            };
        }
    }
}
