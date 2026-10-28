<?php

namespace App\Services\Tracking;

use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderSub;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Support\ManufacturingExcel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manufaktur & Assembly (MA) and Manufaktur & Jasa Lain-Lain (MJ) — one feature,
 * the two sheets of the Excel "7. Tracking Manufaktur & Assembly".
 *
 * An order is one No. MA / MJ; its subs are the Excel rows, one per unit
 * (Sub MA = batch, SUB-02 = next NPBG for the same units). Row status follows
 * the Excel formula (App\Support\ManufacturingExcel) and is recomputed on every
 * change; the order status is the roll-up of its rows.
 */
class ManufacturingService
{
    /** Fields a user types per unit row (Excel columns). */
    public const ROW_FIELDS = ['row_date', 'row_location', 'product_name', 'serial_no_raw', 'item_no', 'process', 'note_start', 'npbg_no',
        'file_name', 'finish_date', 'ri_no', 'note_end'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccurateDocRefs $refs,
    ) {}

    /**
     * @param  array{kind?:string, date?:?string, product_name?:?string, location?:?string, site_id?:?int, vendor_id?:?int, lines?:array}  $data
     */
    public function createOrder(User $user, array $data): ManufacturingOrder
    {
        $kind = strtoupper($data['kind'] ?? 'ASSEMBLY');
        // Jasa is done by someone outside: a vendor record or at least the Excel "Lokasi" text.
        if ($kind === 'JASA' && empty($data['vendor_id']) && trim((string) ($data['location'] ?? '')) === '') {
            throw ValidationException::withMessages(['location' => ['Lokasi / vendor wajib diisi untuk order JASA.']]);
        }
        $date = Carbon::parse($data['date'] ?? now());

        return DB::transaction(function () use ($user, $data, $kind, $date) {
            $order = ManufacturingOrder::create([
                // Excel numbering: MA/25/VII/001 (manufaktur & assembly), MJ/25/VIII/021 (jasa) — per month
                'number' => $this->numbers->nextShort($kind === 'JASA' ? 'MJ' : 'MA', $date),
                'kind' => $kind,
                'date' => $date->toDateString(),
                'product_name' => $data['product_name'] ?? null,
                'location' => $data['location'] ?? ($kind === 'JASA' ? null : 'SIG'),
                'site_id' => $data['site_id'] ?? ($user->site_id ?? \App\Models\Site::query()->orderBy('id')->value('id')),
                // Lokasi of a jasa = the vendor when it is one Accurate knows
                'vendor_id' => $data['vendor_id'] ?? self::vendorIdFor($data['location'] ?? null),
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

    /**
     * One more unit row. `new_sub` starts the next Sub (SUB-02 …), otherwise the
     * row joins the order's latest Sub.
     */
    public function addSub(ManufacturingOrder $order, array $data): ManufacturingOrderSub
    {
        return DB::transaction(function () use ($order, $data) {
            $sub = $this->insertRow($order, $data);
            $this->rollUp($order);

            return $sub;
        });
    }

    /** Edit any Excel column of a row; the status is recomputed from the formula. */
    public function updateSub(ManufacturingOrderSub $sub, array $data): ManufacturingOrderSub
    {
        return DB::transaction(function () use ($sub, $data) {
            $sub->fill($this->rowAttributes($data, $sub));
            $this->refreshRow($sub);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    /**
     * "Selesai pengerjaan": Tgl Selesai, No. RI, Keterangan Akhir and Foto Hasil.
     * Like the Excel, the row only turns COMPLETED once all three of foto, tanggal
     * and RI are there.
     */
    public function completeSub(ManufacturingOrderSub $sub, array $data): ManufacturingOrderSub
    {
        return DB::transaction(function () use ($sub, $data) {
            $sub->fill($this->rowAttributes([
                'finish_date' => $data['finish_date'] ?? $sub->finish_date?->toDateString() ?? now()->toDateString(),
                'ri_no' => $data['ri_no'] ?? $sub->ri_no,
                'note_end' => $data['note_end'] ?? $sub->note_end,
            ] + (array_key_exists('file_name', $data) ? ['file_name' => $data['file_name']] : []), $sub));
            if (! empty($data['photo'])) {
                $this->storePhoto($sub, $data['photo']);
            }
            $this->refreshRow($sub);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    /** Foto Hasil — a `data:image/...;base64,...` string, saved under the Excel "Nama File". */
    public function setPhoto(ManufacturingOrderSub $sub, string $dataUrl): ManufacturingOrderSub
    {
        return DB::transaction(function () use ($sub, $dataUrl) {
            $this->storePhoto($sub, $dataUrl);
            $this->refreshRow($sub);
            $this->rollUp($sub->order);

            return $sub->refresh();
        });
    }

    /** @param array{product_name?:?string, date?:?string, vendor_id?:?int, location?:?string} $data */
    public function updateOrder(ManufacturingOrder $order, array $data): ManufacturingOrder
    {
        $order->update([
            'product_name' => $data['product_name'] ?? $order->product_name,
            'location' => array_key_exists('location', $data) ? $data['location'] : $order->location,
            'date' => isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : $order->date,
            'vendor_id' => $data['vendor_id'] ?? $order->vendor_id,
        ]);

        return $order->refresh();
    }

    /** Hapus nomor MA/MJ — hanya selagi belum ada baris. */
    public function deleteOrder(ManufacturingOrder $order): void
    {
        abort_if($order->subs()->exists(), 422, 'Nomor ini sudah punya baris — hapus barisnya dulu.');
        $order->delete();
    }

    /** Hapus baris — selama belum COMPLETED. */
    public function deleteSub(ManufacturingOrderSub $sub): void
    {
        if ($sub->status === ManufacturingExcel::COMPLETED) {
            throw ValidationException::withMessages(['status' => ['Baris yang sudah COMPLETED tidak bisa dihapus.']]);
        }
        DB::transaction(function () use ($sub) {
            $order = $sub->order;
            if ($sub->photo_path) {
                Storage::disk('local')->delete($sub->photo_path);
            }
            $sub->delete();
            $this->rollUp($order);
        });
    }

    public static function fileNameOf(ManufacturingOrderSub $sub, ?ManufacturingOrder $order = null): ?string
    {
        $order ??= $sub->order;

        return ManufacturingExcel::fileName($order->kind, $order->number, $sub->sub_no,
            $sub->product_name ?? $order->product_name, $sub->serial_no_raw, $sub->item_no);
    }

    // ------------------------------------------------------------------ internals

    private function insertRow(ManufacturingOrder $order, array $data): ManufacturingOrderSub
    {
        $latestSub = $order->subs()->orderByDesc('sub_no')->value('sub_no');
        $subNo = match (true) {
            ! empty($data['sub_no']) => strtoupper($data['sub_no']),
            $latestSub === null => 'SUB-01',
            ! empty($data['new_sub']) => 'SUB-'.str_pad((string) ((int) substr($latestSub, 4) + 1), 2, '0', STR_PAD_LEFT),
            default => $latestSub,
        };

        $sub = new ManufacturingOrderSub([
            'manufacturing_order_id' => $order->id, 'sub_no' => $subNo,
            // Excel "No": next row of the sheet
            'sheet_no' => $this->nextSheetNo($order->kind),
        ]);
        $sub->setRelation('order', $order);
        $sub->fill($this->rowAttributes([
            // Excel "Item No": running number over the whole sheet (MA or MJ)
            'item_no' => ($data['item_no'] ?? null) ?: $this->nextItemNo($order->kind),
            'product_name' => ($data['product_name'] ?? null) ?: $order->product_name,
            'row_date' => ($data['row_date'] ?? null) ?: $order->date?->toDateString(),
            'row_location' => ($data['row_location'] ?? null) ?: $order->location,
        ] + $data, $sub));
        $this->refreshRow($sub);

        return $sub;
    }

    private function nextSheetNo(string $kind): int
    {
        return 1 + (int) ManufacturingOrderSub::query()
            ->whereHas('order', fn ($q) => $q->where('kind', $kind))
            ->max('sheet_no');
    }

    /** Accurate item whose description is exactly this Hasil Produk (case/space-insensitive). */
    public static function itemIdFor(?string $product): ?int
    {
        $p = trim(preg_replace('/\s+/', ' ', (string) $product));

        return $p === '' ? null : \App\Models\Item::query()->whereRaw('UPPER(description) = ?', [mb_strtoupper($p)])->orderBy('id')->value('id');
    }

    /** Vendor whose name is exactly this Lokasi. */
    public static function vendorIdFor(?string $location): ?int
    {
        $l = trim(preg_replace('/\s+/', ' ', (string) $location));

        return $l === '' || strtoupper($l) === 'SIG' ? null
            : \App\Models\Vendor::query()->whereRaw('UPPER(name) = ?', [mb_strtoupper($l)])->orderBy('id')->value('id');
    }

    private function nextItemNo(string $kind): int
    {
        return 1 + (int) ManufacturingOrderSub::query()
            ->whereHas('order', fn ($q) => $q->where('kind', $kind))
            ->max('item_no');
    }

    /** Only the keys present in $data; NPBG / RI numbers are resolved against the Accurate mirror. */
    private function rowAttributes(array $data, ManufacturingOrderSub $sub): array
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
                'ri_no' => $out += $v === '-' ? ['ri_no' => '-', 'ri_id' => null] : ['ri_no' => AccurateDocRefs::normalizeNo($v), 'ri_id' => $this->refs->riId($v)],
                'finish_date', 'row_date' => $out[$f] = $v ? Carbon::parse($v)->toDateString() : null,
                'product_name' => $out += ['product_name' => $v, 'item_id' => self::itemIdFor($v)],
                default => $out[$f] = $v,
            };
        }

        return $out;
    }

    /** Status per the Excel formula, then save. */
    private function refreshRow(ManufacturingOrderSub $sub): void
    {
        $order = $sub->order;
        // Excel: Foto Hasil is the link made from "Nama File" — an uploaded photo counts the same
        $sub->status = ManufacturingExcel::status(
            $sub->file_name ?: $sub->photo_path, $sub->finish_date?->toDateString(), $sub->ri_no, $sub->npbg_no,
            $sub->product_name ?? $order->product_name, $sub->serial_no_raw, $sub->process,
        );
        $sub->save();
    }

    private function rollUp(ManufacturingOrder $order): void
    {
        $statuses = $order->subs()->pluck('status');
        $status = ManufacturingExcel::orderStatus($statuses);
        $order->update([
            'status' => $status,
            'completed_at' => $status === ManufacturingExcel::COMPLETED ? $order->subs()->max('finish_date') : null,
        ]);
    }

    private function storePhoto(ManufacturingOrderSub $sub, string $dataUrl): void
    {
        if (! preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $dataUrl, $m)) {
            throw ValidationException::withMessages(['photo' => ['Foto harus berupa gambar JPG / PNG / WEBP.']]);
        }
        $bytes = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['photo' => ['File foto tidak terbaca.']]);
        }
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        // Excel folder "DATA BUKTI/Foto - Manufaktur & Assembly/<sheet>/<Nama File>.jpg"
        $name = Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', '-', (string) self::fileNameOf($sub)), 150, '') ?: 'foto';
        $path = 'manufacturing/'.strtolower($sub->order->kind)."/{$sub->id}/{$name}.{$ext}";
        if ($sub->photo_path && $sub->photo_path !== $path) {
            Storage::disk('local')->delete($sub->photo_path);
        }
        Storage::disk('local')->put($path, $bytes);
        $sub->photo_path = $path;
        $sub->file_name = $sub->file_name ?: self::fileNameOf($sub);
    }
}
