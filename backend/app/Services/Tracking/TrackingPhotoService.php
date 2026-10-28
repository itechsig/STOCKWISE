<?php

namespace App\Services\Tracking;

use App\Models\TrackingPhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Evidence photos of every tracking module, one per row + slot, stored on the
 * local disk under tracking-photos/<entity>/<id>/<slot>-<name>.<ext>.
 */
class TrackingPhotoService
{
    /** entity => [view permission, edit permission, allowed slots] */
    public const ENTITIES = [
        'maintenance_sub' => ['maintenance.view', 'maintenance.update', ['request', 'before', 'after']],
        'stpp' => ['stpp.view', 'stpp.update', ['out', 'in']],
        'tyre' => ['tyre.view', 'tyre.update', ['out', 'in']],
        'tyre_bpn' => ['tyre.view', 'tyre.update', ['photo']],
        'tyre_bpn_transfer' => ['tyre.view', 'tyre.update', ['out', 'in']],
        'used_return_line' => ['used_return.view', 'used_return.update', ['out', 'in']],
        'lend' => ['lend.view', 'lend.update', ['out', 'in']],
        'borrow' => ['borrow.view', 'borrow.update', ['in', 'out']],
        // Kartu Stock: per line of the card (entity_id = stock_card_entries.id) — a document (photo / PDF) and a photo
        'stock_card_entry' => ['item.view', 'item.update', ['doc', 'foto']],
        // Request Barang serah terima: photo of the handover + the receiver's signature (served by ItemRequestController::handoverFile)
        'item_request' => ['item_request.view', 'item_request.handover', ['handover', 'signature']],
        // ... and per fulfilment (NPBG #1, #2 ...) since partial handovers (ItemRequestController::fulfillmentFile)
        'item_request_fulfillment' => ['item_request.view', 'item_request.handover', ['handover', 'signature']],
        // ... and up to 3 images the requester attached to the request (served by ItemRequestController::image)
        'item_request_image' => ['item_request.view', 'item_request.create', ['img1', 'img2', 'img3']],
        // ... per item: up to 3 images of that item, shown beside its name (entity_id = item_request_lines.id)
        'item_request_line_image' => ['item_request.view', 'item_request.create', ['img1', 'img2', 'img3']],
        // Retur Pembelian: the returned goods, the shipment to the vendor (served by PurchaseReturnController::photo)
        'purchase_return' => ['purchase_return.view', 'purchase_return.manage', ['barang', 'kirim']],
        // PO STOCKWISE: the proof of purchase (nota / invoice, photo or PDF) — served by PurchaseOrderController::proof
        'purchase_order' => ['po.view', 'po.send', ['bukti']],
        // RI manual: up to 3 photos of the goods received (required when it is made; served by RiController::photo)
        'ri' => ['ri.view', 'ri.create', ['foto1', 'foto2', 'foto3']],
        // ... and up to 3 documents (surat jalan / invoice, photo or PDF), kept on the first line of the RI
        'ri_doc' => ['ri.view', 'ri.create', ['doc1', 'doc2', 'doc3']],
    ];

    /** Entities that accept a PDF besides photos. */
    public const DOCUMENT_ENTITIES = ['stock_card_entry', 'purchase_order', 'ri_doc'];

    /**
     * Entities whose rows take any number of files: an upload with slot "auto" gets a
     * fresh slot of its own ("f" + random), so it is added instead of replacing one.
     */
    public const MULTI_ENTITIES = ['stock_card_entry'];

    public function put(string $entity, int $id, string $slot, string $bytes, string $ext, ?string $fileName = null, string $source = 'UPLOAD', ?int $userId = null): TrackingPhoto
    {
        if ($slot === 'auto' && in_array($entity, self::MULTI_ENTITIES, true)) {
            $slot = 'f'.Str::lower(Str::random(12));
        }
        $this->assertSlot($entity, $slot);
        $ext = strtolower($ext) === 'jpeg' ? 'jpg' : strtolower($ext);
        $name = Str::limit(preg_replace('/[\\\\\/:*?"<>|]+/', '-', (string) ($fileName ?: $slot)), 150, '');
        $path = "tracking-photos/{$entity}/{$id}/{$slot}-{$name}.{$ext}";

        $old = TrackingPhoto::where(compact('entity', 'slot') + ['entity_id' => $id])->first();
        if ($old && $old->path !== $path) {
            Storage::disk('local')->delete($old->path);
        }
        Storage::disk('local')->put($path, $bytes);

        return TrackingPhoto::updateOrCreate(
            ['entity' => $entity, 'entity_id' => $id, 'slot' => $slot],
            ['path' => $path, 'file_name' => $fileName, 'mime' => $this->mime($ext), 'source' => $source, 'uploaded_by' => $userId],
        );
    }

    /** From an upload: `data:image/…;base64,…` (or a PDF where the entity takes documents, e.g. the stock card). */
    public function putDataUrl(string $entity, int $id, string $slot, string $dataUrl, ?string $fileName, ?int $userId): TrackingPhoto
    {
        $types = in_array($entity, self::DOCUMENT_ENTITIES, true) ? 'png|jpe?g|webp|pdf' : 'png|jpe?g|webp';
        if (! preg_match('#^data:(?:image|application)/('.$types.');base64,#i', $dataUrl, $m)) {
            throw ValidationException::withMessages(['photo' => [in_array($entity, self::DOCUMENT_ENTITIES, true)
                ? 'File harus berupa gambar JPG / PNG / WEBP atau PDF.' : 'Foto harus berupa gambar JPG / PNG / WEBP.']]);
        }
        $bytes = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if (! $bytes) {
            throw ValidationException::withMessages(['photo' => ['File foto tidak terbaca.']]);
        }

        return $this->put($entity, $id, $slot, $bytes, $m[1], $fileName, 'UPLOAD', $userId);
    }

    public function delete(TrackingPhoto $photo): void
    {
        Storage::disk('local')->delete($photo->path);
        $photo->delete();
    }

    public function has(string $entity, int $id, string $slot): bool
    {
        return TrackingPhoto::where(['entity' => $entity, 'entity_id' => $id, 'slot' => $slot])->exists();
    }

    /**
     * All files of many rows of a multi-file entity, in upload order.
     *
     * @param  iterable<int>  $ids
     * @return array<int, list<array{id:int, file_name:?string, mime:?string}>> id => files
     */
    public function listForRows(string $entity, iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (TrackingPhoto::where('entity', $entity)->whereIn('entity_id', $ids)->orderBy('id')->get(['id', 'entity_id', 'file_name', 'mime']) as $p) {
            $out[$p->entity_id][] = ['id' => $p->id, 'file_name' => $p->file_name, 'mime' => $p->mime];
        }

        return $out;
    }

    /**
     * Photos of many rows at once, for list pages.
     *
     * @param  iterable<int>  $ids
     * @return array<int, array<string, array{id:int, file_name:?string}>> id => slot => photo
     */
    public function forRows(string $entity, iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (TrackingPhoto::where('entity', $entity)->whereIn('entity_id', $ids)->get(['id', 'entity_id', 'slot', 'file_name']) as $p) {
            $out[$p->entity_id][$p->slot] = ['id' => $p->id, 'file_name' => $p->file_name];
        }

        return $out;
    }

    /**
     * Index of an evidence folder: normalized file name (no extension) => full paths.
     *
     * @return array<string, list<string>>
     */
    public static function indexFolder(string $dir): array
    {
        $found = [];
        if (! is_dir($dir)) {
            return $found;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            // the Excel evidence is mostly photos, some are short videos (.mp4)
            if (in_array(strtolower($f->getExtension()), ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'], true)) {
                $found[self::key(pathinfo($f->getFilename(), PATHINFO_FILENAME))][] = $f->getPathname();
            }
        }

        return $found;
    }

    public static function key(?string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim(preg_replace('/\.(jpe?g|png|webp|mp4|mov)$/i', '', (string) $name))));
    }

    private function assertSlot(string $entity, string $slot): void
    {
        $multi = in_array($entity, self::MULTI_ENTITIES, true) && preg_match('/^f[a-z0-9]{1,19}$/', $slot);
        if (! isset(self::ENTITIES[$entity]) || (! $multi && ! in_array($slot, self::ENTITIES[$entity][2], true))) {
            throw ValidationException::withMessages(['slot' => ["Slot foto {$entity}/{$slot} tidak dikenal."]]);
        }
    }

    private function mime(string $ext): string
    {
        return match ($ext) {
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'emf', 'wmf' => 'image/'.$ext,
            'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', default => 'image/jpeg',
        };
    }
}
