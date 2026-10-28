<?php

namespace App\Services\Tracking;

use App\Models\MaintenanceOrder;
use App\Models\MaintenanceOrderSub;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Support\MaintenanceExcel;
use App\Support\ManufacturingExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance Assets = the Excel "6. Tracking Maintenance Assets": a No. SPK with
 * its Sub SPK rows. Row status is the Excel formula (App\Support\MaintenanceExcel),
 * recomputed on every change; the SPK status is the roll-up of its rows.
 * Photos (Permintaan, Foto Sebelum, Foto Sesudah) are tracking_photos of entity
 * "maintenance_sub" (slots request / before / after).
 */
class MaintenanceService
{
    public const ENTITY = 'maintenance_sub';

    /** Excel columns of one Sub SPK row. */
    public const ROW_FIELDS = ['report_date', 'nopol_raw', 'problem_detail', 'workshop_raw', 'request_note', 'npbg_no',
        'before_file_name', 'after_file_name', 'finish_date', 'result_note'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccurateDocRefs $refs,
        private readonly TrackingPhotoService $photos,
    ) {}

    public function createOrder(User $user, array $data): MaintenanceOrder
    {
        $date = Carbon::parse($data['report_date'] ?? now());

        return DB::transaction(function () use ($user, $data, $date) {
            $order = MaintenanceOrder::create([
                // Excel numbering: MK/25/VII/001
                'number' => $this->numbers->nextShort('MK', $date),
                'report_date' => $date->toDateString(),
                'asset_id' => $data['asset_id'] ?? null,
                'nopol_raw' => isset($data['nopol_raw']) ? mb_strtoupper(trim($data['nopol_raw'])) : null,
                'site_id' => $data['site_id'] ?? ($user->site_id ?? \App\Models\Site::query()->orderBy('id')->value('id')),
                'reported_by' => $data['reported_by'] ?? null,
                'problem_summary' => $data['problem_summary'] ?? null,
                'status' => ManufacturingExcel::REQUESTED,
                'created_by' => $user->id,
            ]);
            foreach ($data['lines'] ?? [] as $line) {
                $this->insertRow($order, $line);
            }
            $this->rollUp($order);

            return $order->refresh();
        });
    }

    /** One more Sub SPK row (next SUB-nn unless given). */
    public function addSub(MaintenanceOrder $order, array $data): MaintenanceOrderSub
    {
        return DB::transaction(function () use ($order, $data) {
            $sub = $this->insertRow($order, $data);
            $this->rollUp($order);

            return $sub;
        });
    }

    public function updateSub(MaintenanceOrderSub $sub, array $data): MaintenanceOrderSub
    {
        return DB::transaction(function () use ($sub, $data) {
            $sub->fill($this->rowAttributes($data));
            $this->refreshRow($sub);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    /** "Selesai pengerjaan": Tgl Selesai, Keterangan Akhir (+ Foto Sesudah). COMPLETED once NPBG, foto sesudah and tanggal are there. */
    public function completeSub(MaintenanceOrderSub $sub, array $data, ?int $userId = null): MaintenanceOrderSub
    {
        return DB::transaction(function () use ($sub, $data, $userId) {
            $sub->fill($this->rowAttributes([
                'finish_date' => $data['finish_date'] ?? $sub->finish_date?->toDateString() ?? now()->toDateString(),
                'result_note' => $data['result_note'] ?? $sub->result_note,
            ] + array_intersect_key($data, array_flip(['npbg_no']))));
            if (! empty($data['photo'])) {
                $name = MaintenanceExcel::fileName($sub->order->number, $sub->sub_no, 'after');
                $this->photos->putDataUrl(self::ENTITY, $sub->id, 'after', $data['photo'], $name, $userId);
                $sub->after_file_name = $sub->after_file_name ?: $name;
            }
            $this->refreshRow($sub);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    /** After a photo upload / removal: Nama File follows, status recomputed. */
    public function photoChanged(MaintenanceOrderSub $sub, string $slot, bool $present): void
    {
        $col = ['before' => 'before_file_name', 'after' => 'after_file_name'][$slot] ?? null;
        if ($col) {
            $sub->{$col} = $present ? ($sub->{$col} ?: MaintenanceExcel::fileName($sub->order->number, $sub->sub_no, $slot)) : null;
        }
        $this->refreshRow($sub);
        $this->rollUp($sub->order);
    }

    public function updateOrder(MaintenanceOrder $order, array $data): MaintenanceOrder
    {
        $order->update([
            'asset_id' => $data['asset_id'] ?? $order->asset_id,
            'nopol_raw' => isset($data['nopol_raw']) ? mb_strtoupper(trim($data['nopol_raw'])) : $order->nopol_raw,
            'report_date' => isset($data['report_date']) ? Carbon::parse($data['report_date'])->toDateString() : $order->report_date,
            'reported_by' => $data['reported_by'] ?? $order->reported_by,
            'problem_summary' => $data['problem_summary'] ?? $order->problem_summary,
        ]);

        return $order->refresh();
    }

    /** Hapus SPK — hanya selagi belum ada Sub SPK. */
    public function deleteOrder(MaintenanceOrder $order): void
    {
        abort_if($order->subs()->exists(), 422, 'SPK sudah punya sub-pekerjaan — hapus sub-nya dulu.');
        $order->delete();
    }

    /** Hapus Sub SPK — selama belum COMPLETED. */
    public function deleteSub(MaintenanceOrderSub $sub): void
    {
        if ($sub->status === ManufacturingExcel::COMPLETED) {
            throw ValidationException::withMessages(['status' => ['Sub SPK yang sudah COMPLETED tidak bisa dihapus.']]);
        }
        DB::transaction(function () use ($sub) {
            $order = $sub->order;
            foreach (\App\Models\TrackingPhoto::where(['entity' => self::ENTITY, 'entity_id' => $sub->id])->get() as $p) {
                $this->photos->delete($p);
            }
            $sub->delete();
            $this->rollUp($order);
        });
    }

    public function refreshRow(MaintenanceOrderSub $sub): void
    {
        $id = $sub->id;
        $sub->status = MaintenanceExcel::status(
            $sub->npbg_no,
            trim((string) $sub->after_file_name) !== '' || ($id && $this->photos->has(self::ENTITY, $id, 'after')),
            $sub->finish_date?->toDateString(),
            trim((string) $sub->request_note) !== '' || ($id && $this->photos->has(self::ENTITY, $id, 'request')),
        );
        $sub->save();
    }

    public function rollUp(MaintenanceOrder $order): void
    {
        $status = ManufacturingExcel::orderStatus($order->subs()->pluck('status'));
        $order->update([
            'status' => $status,
            'completed_at' => $status === ManufacturingExcel::COMPLETED ? $order->subs()->max('finish_date') : null,
        ]);
    }

    private function insertRow(MaintenanceOrder $order, array $data): MaintenanceOrderSub
    {
        $n = $order->subs()->count() + 1;
        $sub = new MaintenanceOrderSub([
            'maintenance_order_id' => $order->id,
            'sub_no' => ! empty($data['sub_no']) ? strtoupper($data['sub_no']) : 'SUB-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'sheet_no' => 1 + (int) MaintenanceOrderSub::max('sheet_no'),
        ]);
        $sub->setRelation('order', $order);
        $sub->fill($this->rowAttributes([
            'report_date' => ($data['report_date'] ?? null) ?: now()->toDateString(),
            'nopol_raw' => ($data['nopol_raw'] ?? null) ?: $order->nopol_raw,
        ] + $data));
        $this->refreshRow($sub);

        return $sub;
    }

    private function rowAttributes(array $data): array
    {
        $out = [];
        foreach (self::ROW_FIELDS as $f) {
            if (! array_key_exists($f, $data)) {
                continue;
            }
            $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
            $v = $v === '' ? null : $v;
            match ($f) {
                // "-" is a value in the Excel (counts as filled), not a document
                'npbg_no' => $out += $v === '-' ? ['npbg_no' => '-', 'npbg_id' => null] : ['npbg_no' => AccurateDocRefs::normalizeNo($v), 'npbg_id' => $this->refs->npbgId($v)],
                'report_date', 'finish_date' => $out[$f] = $v ? Carbon::parse($v)->toDateString() : null,
                'nopol_raw' => $out[$f] = $v ? mb_strtoupper($v) : null,
                default => $out[$f] = $v,
            };
        }

        return $out;
    }
}
