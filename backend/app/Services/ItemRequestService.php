<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemRequest;
use App\Models\ItemRequestLine;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\ItemRequestForms;
use App\Support\Network\IpClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Request Barang (the parent transaction): karyawan asks → admin gudang VERIFIES each line (matches it to a
 * Master Produk item, Barang or Jasa — one not in the master yet is added there first, stock 0 — and may
 * propose a lower quantity) — no NPBG / PPB choice: every request goes into approval
 * (Manager → Direktur, ItemRequestApprovalService; they may lower the quantity or send it back —
 * PERLU_PERBAIKAN, then the admin verifies again). Once approved the SYSTEM decides from the real stock
 * (ItemRequestFulfillmentService::allocate): enough → NPBG of all, short → NPBG of what there is + the rest
 * to a Draft PPB, none (or a new item) → Draft PPB. Later deliveries are given by Gudang (NPBG lanjutan).
 */
class ItemRequestService
{
    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * A request from a logged-in user (source APP) or through a share link (source LINK, no account).
     * Lines are free text — the requester names the item however they like; the admin matches it
     * to a master item later (ItemRecommender).
     *
     * @param  array{id?:?int,name:string,contact?:?string,ip?:?string,link_id?:?int}  $requester
     * @param  array{form_type?:?string,form_detail?:?string,divisi?:?string,needed_date?:?string,note?:?string,lines:list<array{item_id?:?int,kode_barang?:?string,deskripsi_barang:string,qty:float,satuan?:?string,note?:?string}>}  $data
     */
    /** Photo entity of the images the requester attaches (img1..img3): shown from the request to its end. */
    public const IMAGE_ENTITY = 'item_request_image';

    public const MAX_IMAGES = 3;

    /** Photo entity of the images of one item of the request (img1..img3), shown beside its name. */
    public const LINE_IMAGE_ENTITY = 'item_request_line_image';

    /**
     * @param  iterable<int>  $lineIds
     * @return array<int, list<string>> line id => its image slots
     */
    public static function lineImages(iterable $lineIds): array
    {
        $ids = collect($lineIds)->filter()->values();

        return $ids->isEmpty() ? [] : \App\Models\TrackingPhoto::where('entity', self::LINE_IMAGE_ENTITY)->whereIn('entity_id', $ids)
            ->orderBy('slot')->get(['entity_id', 'slot'])->groupBy('entity_id')->map(fn ($g) => $g->pluck('slot')->values()->all())->all();
    }

    /** @return list<string> the image slots of a request */
    public static function images(int $id): array
    {
        return \App\Models\TrackingPhoto::where(['entity' => self::IMAGE_ENTITY, 'entity_id' => $id])->orderBy('slot')->pluck('slot')->values()->all();
    }

    /** The requester's images (data URLs, max 3) on the request. */
    private function storeImages(ItemRequest $request, array $images, ?int $userId): void
    {
        $files = app(\App\Services\Tracking\TrackingPhotoService::class);
        foreach (array_slice(array_values($images), 0, self::MAX_IMAGES) as $i => $image) {
            $files->putDataUrl(self::IMAGE_ENTITY, $request->id, 'img'.($i + 1), $image, "gambar-{$request->number}-".($i + 1), $userId);
        }
    }

    public function create(array $requester, array $data): ItemRequest
    {
        $type = isset(ItemRequestForms::TYPES[$data['form_type'] ?? '']) ? $data['form_type'] : 'UMUM';
        $form = ItemRequestForms::describe($type);
        $request = DB::transaction(function () use ($requester, $data, $type, $form) {
            $request = ItemRequest::create([
                'number' => $this->numbers->nextShort('REQ'),
                // the requester follows the request with this (Cek Permintaan) — no account needed
                'track_token' => Str::random(32),
                'form_type' => $type,
                // never the requester's call — admin gudang marks it urgent afterwards (ItemRequestController::urgency)
                'urgency' => null,
                'form_detail' => $form['detail_label'] && filled($data['form_detail'] ?? null) ? mb_substr(trim($data['form_detail']), 0, 200) : null,
                'source' => ! empty($requester['link_id']) ? 'LINK' : 'APP',
                'link_id' => $requester['link_id'] ?? null,
                'requester_id' => $requester['id'] ?? null,
                'requester_name' => mb_substr($requester['name'], 0, 150),
                'requester_contact' => $requester['contact'] ?? null,
                'requester_ip' => $requester['ip'] ?? null,
                'requester_location' => isset($requester['ip']) ? IpClassifier::classify($requester['ip']) : null,
                'divisi' => filled($data['divisi'] ?? null) ? $data['divisi'] : $form['default_divisi'],
                'needed_date' => $data['needed_date'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => 'MENUNGGU',
            ]);
            foreach ($data['lines'] as $line) {
                $item = ! empty($line['item_id']) ? Item::with('unit:id,code')->find($line['item_id']) : null;
                $made = $request->lines()->create([
                    'item_id' => $item?->id,
                    'kode_barang' => $item?->code ?? ($line['kode_barang'] ?? null),
                    'deskripsi_barang' => $line['deskripsi_barang'],
                    'qty' => $line['qty'],
                    'satuan' => $line['satuan'] ?? $item?->unit?->code,
                    'note' => $line['note'] ?? null,
                ]);
                // the images of this item, beside its name from the request to its end
                $files = app(\App\Services\Tracking\TrackingPhotoService::class);
                foreach (array_slice(array_values($line['images'] ?? []), 0, self::MAX_IMAGES) as $i => $image) {
                    $files->putDataUrl(self::LINE_IMAGE_ENTITY, $made->id, 'img'.($i + 1), $image, "gambar-{$request->number}-{$made->id}-".($i + 1), $requester['id'] ?? null);
                }
            }
            // the requester's images go with it (a bad file refuses the whole request)
            $this->storeImages($request, $data['images'] ?? [], $requester['id'] ?? null);

            return $request;
        });

        $urgent = $request->urgency === 'URGENT';
        NotificationDispatcher::toPermission('item_request.process', 'request', $urgent ? 'warning' : 'info',
            ($urgent ? 'URGENT: ' : '').$form['label'].' baru',
            "{$request->number} dari {$request->requester_name}, {$request->lines()->count()} barang, menunggu diproses.",
            "/item-requests/{$request->id}", $requester['id'] ?? null);

        return $request;
    }

    /**
     * Admin gudang verifies the open lines and sends the request to approval.
     *
     * @param  array<int, array{item_id?:?int, qty?:float|string|null}>  $lines  line id => master item (null = new item, keep the
     *         requester's words) and the quantity proposed (≤ asked; default as asked)
     * @param  array{pelanggan?:?string,tipe_npbg?:?string}  $npbg  header of the NPBG(s) made later
     */
    public function process(ItemRequest $request, User $admin, array $lines, array $npbg): ItemRequest
    {
        DB::transaction(function () use ($request, $admin, $lines, $npbg) {
            $request = ItemRequest::lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['MENUNGGU', 'DIPROSES', 'PERLU_PERBAIKAN'], true)) {
                throw ValidationException::withMessages(['status' => 'Request ini sudah '.ItemRequestApprovalService::label($request->status).'.']);
            }
            /** @var Collection<int, ItemRequestLine> $open */
            $open = $request->lines()->whereNull('decision')->get()->keyBy('id');
            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Tidak ada barang yang perlu diverifikasi.']);
            }
            $pelanggan = trim((string) ($npbg['pelanggan'] ?? $request->npbg_pelanggan ?? ''));
            $request->fill([
                'npbg_pelanggan' => mb_substr($pelanggan !== '' ? $pelanggan : ($request->divisi ?: (string) $request->requester_name), 0, 200),
                'npbg_tipe' => mb_substr((string) ($npbg['tipe_npbg'] ?? $request->npbg_tipe ?? 'Non Penjualan'), 0, 20),
            ]);
            foreach ($open as $id => $line) {
                $in = $lines[$id] ?? [];
                $itemId = ! empty($in['item_id']) ? (int) $in['item_id'] : $line->item_id;
                // every line is a Master Produk item: a new one is added there first (Barang / Jasa, stock 0)
                if (! $itemId) {
                    throw ValidationException::withMessages(["lines.{$id}.item_id" => "\"{$line->requestedText()}\" belum ada di Master Produk, tambahkan dulu (Barang / Jasa), lalu pilih."]);
                }
                // the name sent to approval is Master Produk's: the item must still be there, and active
                $item = Item::with('unit:id,code')->find($itemId);
                if (! $item) {
                    throw ValidationException::withMessages(["lines.{$id}.item_id" => "Barang untuk \"{$line->requestedText()}\" tidak ada lagi di Master Produk, pilih ulang."]);
                }
                if (! $item->is_active) {
                    throw ValidationException::withMessages(["lines.{$id}.item_id" => "{$item->description} nonaktif di Master Produk, aktifkan atau pilih barang lain."]);
                }
                $line->matchTo($item);
                $qty = isset($in['qty']) && $in['qty'] !== '' && $in['qty'] !== null ? round((float) $in['qty'], 2) : (float) $line->qty;
                if ($qty <= 0 || $qty > (float) $line->qty + 0.0001) {
                    throw ValidationException::withMessages(["lines.{$id}.qty" => "Jumlah {$line->deskripsi_barang} 1–".rtrim(rtrim(number_format((float) $line->qty, 2, ',', '.'), '0'), ',').' (yang diminta).']);
                }
                // NPBG = "from the warehouse"; whether it is given from stock or bought is decided after approval
                $line->fill(['decision' => 'NPBG', 'approved_qty' => $qty, 'decided_by' => $admin->id, 'decided_at' => now()])->save();
            }
            $request->update(['status' => self::statusOf($request->lines()->get()), 'processed_by' => $admin->id, 'processed_at' => now()]);
        });

        $request->refresh()->load('lines');
        if ($request->status === 'APPROVAL_MANAGER') {
            app(ItemRequestApprovalService::class)->submitted($request, $admin);
        }
        NotificationDispatcher::toUser($request->requester_id, 'request', 'success',
            "Request {$request->number} diverifikasi", 'Status: '.ItemRequestApprovalService::label($request->status).'.', "/item-requests/{$request->id}");

        return $request;
    }

    public function cancel(ItemRequest $request): ItemRequest
    {
        if ($request->status !== 'MENUNGGU') {
            throw ValidationException::withMessages(['status' => 'Request yang sudah diproses tidak bisa dibatalkan.']);
        }
        $request->update(['status' => 'DIBATALKAN']);

        return $request;
    }

    /** @param  Collection<int, ItemRequestLine>  $lines */
    public static function statusOf(Collection $lines): string
    {
        if ($lines->contains(fn ($l) => $l->decision === null)) {
            return $lines->contains(fn ($l) => $l->decision !== null) ? 'DIPROSES' : 'MENUNGGU';
        }

        if ($lines->every(fn ($l) => $l->decision === 'TOLAK')) {
            return 'DITOLAK';
        }

        // goods from stock need approval first; goods to buy wait for purchasing, then Gudang hands them over
        return $lines->contains(fn ($l) => $l->decision === 'NPBG') ? 'APPROVAL_MANAGER' : 'MENUNGGU_PENGADAAN';
    }
}
