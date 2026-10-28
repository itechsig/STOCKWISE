<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'item_type' => $this->item_type,
            // BARANG / JASA (Master Produk)
            'product_kind' => $this->product_kind ?? 'BARANG',
            'needs_blueprint' => $this->needs_blueprint,
            'lead_time_days' => $this->lead_time_days,
            'is_active' => $this->is_active,
            'source' => $this->source,
            'accurate_synced_at' => $this->accurate_synced_at,
            'accurate_qty_onhand' => $this->accurate_qty_onhand,
            'accurate_qty_onorder' => $this->accurate_qty_onorder,
            // Forecast = qty aktual sekarang + qty yang sedang dipesan di PO
            // terbuka (Accurate's own "Qty On Order", disinkron langsung —
            // bukan dihitung ulang dari purchase_order_items internal).
            'forecast_qty' => (float) ($this->accurate_qty_onhand ?? 0) + (float) ($this->accurate_qty_onorder ?? 0),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'path' => $this->category->path,
            ] : null),
            // Kategori Anak 1/2/3 barang: diturunkan dari ITEMDESCRIPTION milik
            // rantai PARENTITEM Accurate (kolom accurate_category_anak_*, diisi
            // oleh AccurateSyncService), BUKAN dari tree kategori Excel
            // (`categories`/category_id — itu masih ada, dipakai fitur lain,
            // lihat field `category` di atas). Kategori Induk BUKAN dari rantai
            // PARENTITEM (level itu selalu null di data Accurate perusahaan
            // ini) — diturunkan dari 3 huruf depan kode barang lewat tabel
            // terjemahan tetap, lihat AccurateSyncService::KATEGORI_INDUK_MAP.
            'category_breakdown' => [
                'induk' => $this->accurate_category_induk,
                'anak_1' => $this->accurate_category_anak_1,
                'anak_2' => $this->accurate_category_anak_2,
                'anak_3' => $this->accurate_category_anak_3,
            ],
            'unit' => $this->whenLoaded('unit', fn () => $this->unit?->only('id', 'code', 'name')),
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_warehouse' => $this->whenLoaded('defaultWarehouse', fn () => $this->defaultWarehouse?->only('id', 'code', 'name')),
            'default_location_id' => $this->default_location_id,
            'default_location' => $this->whenLoaded('defaultLocation', fn () => $this->defaultLocation?->only('id', 'code')),
            'blueprint_img_path' => $this->blueprint_img_path,
            'blueprint_pdf_path' => $this->blueprint_pdf_path,
            'blueprint_3d_ref' => $this->blueprint_3d_ref,
            // Excel lama: kolom "Nama Alias" bukan alias sungguhan (selalu "Tidak" — lihat
            // docs/excel-data-mapping.md §DATA.xlsx col 8). Alias asli ada di item_aliases.
            'alias_name' => 'Tidak',
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->pluck('alias_description')),
            'safety_stock' => $this->whenLoaded('effectiveSafetyStock', fn () => $this->effectiveSafetyStock?->safety_stock),
            'min_pr' => $this->whenLoaded('effectiveSafetyStock', fn () => $this->effectiveSafetyStock?->min_pr),
            'analysis' => $this->whenLoaded('snapshot', fn () => $this->snapshot ? [
                'actual' => $this->snapshot->actual,
                'reserved' => $this->snapshot->reserved,
                'available' => $this->snapshot->available,
                'stock_known' => $this->snapshot->stock_known,
                'safety_stock' => $this->snapshot->safety_stock,
                'selisih' => $this->snapshot->selisih,
                'status' => $this->snapshot->status,
                'deficit' => $this->snapshot->deficit,
                'priority_score' => $this->snapshot->priority_score,
                'priority_level' => $this->snapshot->priority_level,
                'recommendation' => $this->snapshot->recommendation,
                'recommended_qty' => $this->snapshot->recommended_qty,
            ] : null),
            'inventory' => $this->whenLoaded('inventory', fn () => $this->inventory->map(fn ($i) => [
                'warehouse_id' => $i->warehouse_id,
                'actual_qty' => $i->actual_qty,
                'reserved_qty' => $i->reserved_qty,
                'available_qty' => $i->available_qty,
                'stock_known' => $i->stock_known,
            ])),
        ];
    }
}
