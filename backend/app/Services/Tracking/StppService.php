<?php

namespace App\Services\Tracking;

use App\Models\SerialUnit;
use App\Models\StppTransaction;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STPP = the Excel "4. Tracking STPP": one serial unit handed to a holder.
 * ACTIVE (No. NPBG + Bukti Keluar) → PASSIVE (Tanda Kembali = No. RI + Bukti Terima).
 * PASSIVE → handed out again = a new row with the same serial, ACTIVE.
 * Bukti Keluar / Terima are tracking_photos (entity "stpp", slots out / in).
 */
class StppService
{
    /** Excel columns a user types. */
    public const FIELDS = ['serial_no', 'item_id', 'description_raw', 'qty', 'unit_id', 'unit_raw', 'holder_id', 'holder_name_raw',
        'placement_department_id', 'placement_raw', 'out_date', 'out_npbg_no', 'out_item_no', 'out_file_name', 'out_note',
        'return_date', 'return_ri_no', 'return_item_no', 'return_file_name', 'return_note'];

    public function __construct(private readonly DocumentNumberService $numbers, private readonly AccurateDocRefs $refs) {}

    public function issue(User $user, array $data): StppTransaction
    {
        $date = Carbon::parse($data['out_date'] ?? now());

        return DB::transaction(function () use ($user, $data, $date) {
            $stpp = new StppTransaction([
                'number' => $this->numbers->next('STPP', 'SDA', $date),
                'sheet_no' => 1 + (int) StppTransaction::max('sheet_no'),
                'description_raw' => '-',
                'qty' => 1,
                'out_date' => $date->toDateString(),
                'status' => 'ACTIVE',
                'created_by' => $user->id,
            ]);
            $this->fill($stpp, $data + ['out_npbg_id' => $data['out_npbg_id'] ?? null]);
            $stpp->save();

            return $stpp->refresh();
        });
    }

    /** Tanda Kembali: the RI that took the unit back → PASSIVE. */
    public function withdraw(StppTransaction $stpp, array $data): StppTransaction
    {
        $this->assert($stpp, ['ACTIVE', 'INCOMPLETE']);

        return DB::transaction(function () use ($stpp, $data) {
            $this->fill($stpp, $data + ['return_date' => $data['return_date'] ?? now()->toDateString()]);
            $stpp->status = 'PASSIVE';
            if (! empty($data['return_ri_id'])) {
                $stpp->return_ri_id = $data['return_ri_id'];
            }
            $stpp->save();
            $stpp->serialUnit?->update(['status' => 'IN_STOCK']);

            return $stpp->refresh();
        });
    }

    /** PASSIVE -> serahkan lagi: baris baru, serial sama. */
    public function reissue(StppTransaction $stpp, User $user, array $data): StppTransaction
    {
        $this->assert($stpp, ['PASSIVE']);

        return $this->issue($user, array_merge([
            'serial_no' => $stpp->serial_no_raw,
            'item_id' => $stpp->item_id,
            'description_raw' => $stpp->description_raw,
            'qty' => $stpp->qty,
            'unit_id' => $stpp->unit_id,
            'unit_raw' => $stpp->unit_raw,
        ], $data));
    }

    /** Every Excel column stays editable. */
    public function update(StppTransaction $stpp, array $data): StppTransaction
    {
        $this->fill($stpp, $data);
        $stpp->save();

        return $stpp->refresh();
    }

    /** Hapus baris STPP — hanya selagi belum ditarik (PASSIVE = ada riwayat). */
    public function delete(StppTransaction $stpp): void
    {
        $this->assert($stpp, ['ACTIVE', 'INCOMPLETE']);
        foreach (\App\Models\TrackingPhoto::where(['entity' => 'stpp', 'entity_id' => $stpp->id])->get() as $p) {
            app(TrackingPhotoService::class)->delete($p);
        }
        $stpp->delete();
    }

    private function fill(StppTransaction $stpp, array $data): void
    {
        foreach (self::FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                continue;
            }
            $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
            $v = $v === '' ? null : $v;
            match ($f) {
                'serial_no' => $this->serial($stpp, $v, $data['description_raw'] ?? $stpp->description_raw),
                'out_npbg_no' => $stpp->fill($v === '-' || $v === null
                    ? ['out_npbg_no' => $v, 'out_npbg_id' => $data['out_npbg_id'] ?? null]
                    : ['out_npbg_no' => AccurateDocRefs::normalizeNo($v), 'out_npbg_id' => $this->refs->npbgId($v, 'out_npbg_no')]),
                'return_ri_no' => $stpp->return_ri_no = $v === '-' || $v === null ? $v : AccurateDocRefs::normalizeNo($v),
                'out_date', 'return_date' => $stpp->{$f} = $v ? Carbon::parse($v)->toDateString() : null,
                'description_raw' => $stpp->description_raw = $v ?? '-',
                'qty' => $stpp->qty = $v ?? 1,
                default => $stpp->{$f} = $v,
            };
        }
        if (array_key_exists('out_npbg_id', $data) && ! array_key_exists('out_npbg_no', $data) && $data['out_npbg_id']) {
            $stpp->out_npbg_id = $data['out_npbg_id'];
            $stpp->out_npbg_no = \App\Models\Npbg::whereKey($data['out_npbg_id'])->value('no_npbg');
        }
        if (array_key_exists('return_ri_no', $data) && $stpp->return_ri_no && $stpp->return_ri_no !== '-') {
            $this->refs->riId($stpp->return_ri_no, 'return_ri_no'); // must exist in the Accurate RI
        }
    }

    private function serial(StppTransaction $stpp, ?string $serial, ?string $desc): void
    {
        $stpp->serial_no_raw = $serial;
        if (! $serial) {
            $stpp->serial_unit_id = null;

            return;
        }
        $unit = SerialUnit::firstOrCreate(['serial_no' => $serial, 'kind' => 'STPP_TOOL'], ['description_raw' => $desc]);
        $unit->update(['status' => 'IN_USE']);
        $stpp->serial_unit_id = $unit->id;
    }

    /** @param list<string> $allowed */
    private function assert(StppTransaction $stpp, array $allowed): void
    {
        if (! in_array($stpp->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Aksi tidak valid untuk status STPP {$stpp->status}."]]);
        }
    }
}
