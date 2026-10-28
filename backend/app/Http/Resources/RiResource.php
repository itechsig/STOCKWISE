<?php

namespace App\Http\Resources;

use App\Models\Ri;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ri */
class RiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_ri' => $this->no_ri,
            'tgl_ri' => $this->tgl_ri?->toDateString(),
            'is_manual' => $this->accurate_apinvoice_id === null,
            'divisi' => $this->divisi,
            // PO / BEKAS (pengembalian bekas) / MANUAL / ACCURATE (Accurate RI without PO)
            'sumber' => $this->source(),
            'used_return_row_id' => $this->used_return_row_id,
            'vendor' => $this->vendor,
            // Resolved `vendors` row — same one PO sync creates/uses for this Accurate PERSONDATA (not every RI vendor is a real external vendor, e.g. internal stock-take entries; flagged needs_review on the vendor itself, not here).
            'vendor_id' => $this->vendor_id,
            'no_po' => $this->no_po,
            // Accurate's own APITMDET.POID/POSEQ chain — which PO line this RI line received against (resolved FK, not just the free-text no_po header field above).
            'source_po' => $this->whenLoaded('poItem', fn () => $this->poItem ? [
                'purchase_order_id' => $this->poItem->purchase_order_id,
                'purchase_order_item_id' => $this->poItem->id,
                'number' => $this->poItem->purchaseOrder?->number,
                'date' => $this->poItem->purchaseOrder?->date?->toDateString(),
            ] : null),
            // Same PODET.REQID/REQSEQ chain, one hop further back — the PPB this
            // RI's PO line was raised from (the full PPB -> PO -> RI chain, resolved).
            'tgl_ppb' => $this->whenLoaded('poItem', fn () => $this->poItem?->accuratePpb?->tgl_ppb?->toDateString()),
            'shipdate' => $this->shipdate?->toDateString(),
            'kode_barang' => $this->kode_barang,
            'deskripsi_barang' => $this->deskripsi_barang,
            'kuantitas' => $this->kuantitas,
            'satuan' => $this->satuan,
            'harga_satuan' => $this->harga_satuan,
            'pemeriksa' => $this->pemeriksa,
            // template "RI [A5]"
            'no_surat_jalan' => $this->no_surat_jalan,
            'pengirim' => $this->pengirim,
            'fob' => $this->fob,
            'no_ppb' => $this->no_ppb,
            'no_seri' => $this->no_seri,
            'lokasi_gudang' => $this->lokasi_gudang,
            'lokasi_rak' => $this->lokasi_rak,
            'keterangan' => $this->keterangan,
            'accurate_synced_at' => $this->accurate_synced_at,
            // proof photos of a manual RI (slots foto1..foto3), on its detail only
            // the line's own photos (RI dari PO: proof per item), else the ones of the whole RI (first line)
            'photos' => $this->when($request->routeIs('api.ri.show', 'api.ri.store', 'api.ri.store-from-po'), fn () => ($own = \App\Models\TrackingPhoto::where(['entity' => 'ri', 'entity_id' => $this->id])
                ->orderBy('slot')->pluck('slot')->values())->isNotEmpty() ? $own : \App\Models\TrackingPhoto::where(['entity' => 'ri', 'entity_id' => $this->fileOwnerId()])->orderBy('slot')->pluck('slot')->values()),
            // documents (surat jalan / invoice): slot + name + photo or PDF
            'documents' => $this->when($request->routeIs('api.ri.show', 'api.ri.store', 'api.ri.store-from-po'), fn () => \App\Models\TrackingPhoto::where(['entity' => 'ri_doc', 'entity_id' => $this->fileOwnerId()])
                ->orderBy('slot')->get(['slot', 'file_name', 'mime'])),
            // every line of the same RI document (detail)
            'lines' => $this->when($request->routeIs('api.ri.show') && $this->no_ri, function () {
                $rows = Ri::where('no_ri', $this->no_ri)->orderBy('id')->get(['id', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'harga_satuan', 'no_seri', 'no_po', 'no_ppb', 'vendor']);
                // each item's own proof photos
                $photos = \App\Models\TrackingPhoto::where('entity', 'ri')->whereIn('entity_id', $rows->pluck('id'))->orderBy('slot')->get(['entity_id', 'slot'])->groupBy('entity_id');

                return $rows->map(fn ($r) => $r->toArray() + ['photos' => ($photos[$r->id] ?? collect())->pluck('slot')->values()]);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
