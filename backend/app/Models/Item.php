<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'description', 'description_normalized', 'category_id', 'unit_id',
        'item_type', 'product_kind', 'needs_blueprint', 'lead_time_days', 'default_warehouse_id',
        'default_location_id', 'blueprint_img_path', 'blueprint_pdf_path',
        'blueprint_3d_ref', 'source', 'is_active',
        'accurate_synced_at', 'accurate_qty_onhand', 'accurate_qty_onorder',
        'accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3', 'accurate_category_induk',
    ];

    protected function casts(): array
    {
        return [
            'needs_blueprint' => 'boolean',
            'is_active' => 'boolean',
            'lead_time_days' => 'integer',
            // Accurate NUMERIC(18,4) — 66 / 46.656, not "66.0000"
            'accurate_qty_onhand' => 'float',
            'accurate_qty_onorder' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Item $item) {
            $item->description_normalized = self::normalize($item->description);
        });
    }

    public static function normalize(?string $description): string
    {
        return Str::of($description ?? '')
            ->squish()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'default_location_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ItemAlias::class);
    }

    public function safetyStocks(): HasMany
    {
        return $this->hasMany(ItemSafetyStock::class);
    }

    public function effectiveSafetyStock(): HasOne
    {
        return $this->hasOne(ItemSafetyStock::class)->where('is_effective', true);
    }

    /** Catalog-wide analysis snapshot (aggregate across warehouses). */
    public function snapshot(): HasOne
    {
        return $this->hasOne(InventorySnapshot::class)->whereNull('warehouse_id');
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Effective safety stock value (0 when none). */
    public function safetyStockValue(): float
    {
        return (float) ($this->relationLoaded('effectiveSafetyStock')
            ? ($this->effectiveSafetyStock?->safety_stock ?? 0)
            : ($this->safetyStocks()->where('is_effective', true)->value('safety_stock') ?? 0));
    }

    /**
     * Master Barang search/filter query — shared by ItemController::index()
     * (paginated list) and ExportController (streamed export), so "export
     * hasil filter" always matches exactly what the Items table is showing.
     */
    public static function filtered(Request $request): Builder
    {
        $query = static::query()
            ->leftJoin('categories', 'categories.id', '=', 'items.category_id')
            ->leftJoin('inventory_snapshots as snap', function ($join) {
                $join->on('snap.item_id', '=', 'items.id')->whereNull('snap.warehouse_id');
            })
            ->select('items.*');

        // text
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('items.code', 'like', "%{$search}%")
                    ->orWhereLoose('items.description', $search);
            });
        }
        $request->whenFilled('code', fn ($v) => $query->where('items.code', 'like', "%{$v}%"));
        $request->whenFilled('description', fn ($v) => $query->whereLoose('items.description', $v));

        // category (by name at any level, via path) — tree kategori Excel lama
        $request->whenFilled('category_induk', fn ($v) => $query->where('categories.path', 'like', "{$v}%"));
        foreach (['category_anak_1', 'category_anak_2', 'category_anak_3', 'category'] as $key) {
            $request->whenFilled($key, fn ($v) => $query->where('categories.path', 'like', "%{$v}%"));
        }
        $request->whenFilled('category_id', fn ($v) => $query->where('items.category_id', $v));

        // Kategori Anak 1/2/3 hasil sync Accurate (accurate_category_anak_*) —
        // sumber yang sama persis dengan yang ditampilkan di kolom "Kategori
        // Anak 1/2/3" tabel Master Barang. Sengaja dipisah dari filter
        // category_induk/category_anak_* di atas (tree kategori Excel lama,
        // tidak diubah).
        // accurate_category_induk BUKAN dari rantai PARENTITEM Accurate (level
        // itu selalu NULL di data perusahaan ini) — diturunkan dari 3 huruf
        // depan kode barang lewat tabel terjemahan tetap yang diberikan user,
        // lihat AccurateSyncService::KATEGORI_INDUK_MAP. Independen dari
        // anak_1/2/3 (dua sistem klasifikasi yang tidak berhubungan), jadi
        // sengaja tidak dibuat cascading dengan filter anak_1/2/3 di atas.
        $request->whenFilled('accurate_category_induk', fn ($v) => $query->where('items.accurate_category_induk', $v));
        $request->whenFilled('accurate_category_anak_1', fn ($v) => $query->where('items.accurate_category_anak_1', $v));
        $request->whenFilled('accurate_category_anak_2', fn ($v) => $query->where('items.accurate_category_anak_2', $v));
        $request->whenFilled('accurate_category_anak_3', fn ($v) => $query->where('items.accurate_category_anak_3', $v));

        // simple attrs
        // Master Produk: BARANG (goods) / JASA (service)
        $request->whenFilled('product_kind', fn ($v) => $query->where('items.product_kind', strtoupper((string) $v)));
        $request->whenFilled('unit_id', fn ($v) => $query->where('items.unit_id', $v));
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('items.default_warehouse_id', $v));
        $request->whenFilled('needs_blueprint', fn ($v) => $query->where('items.needs_blueprint', filter_var($v, FILTER_VALIDATE_BOOL)));
        $request->whenFilled('is_active', fn ($v) => $query->where('items.is_active', filter_var($v, FILTER_VALIDATE_BOOL)));

        // analysis-derived
        $request->whenFilled('status', fn ($v) => $query->where('snap.status', strtoupper((string) $v)));
        $request->whenFilled('priority_level', fn ($v) => $query->where('snap.priority_level', strtoupper((string) $v)));
        // snap.lead_time_days (not items.lead_time_days) — the snapshot already
        // coalesces item_safety_stocks.lead_time_days (the value the Safety
        // Stock formula actually used) ahead of items.lead_time_days, see
        // InventoryAnalyzer. Filtering on the raw items column here would let
        // this range silently disagree with the Lead Time the same item shows
        // everywhere else once the two columns drift (confirmed they do).
        $request->whenFilled('lead_time_min', fn ($v) => $query->where('snap.lead_time_days', '>=', (int) $v));
        $request->whenFilled('lead_time_max', fn ($v) => $query->where('snap.lead_time_days', '<=', (int) $v));
        $request->whenFilled('selisih_min', fn ($v) => $query->where('snap.selisih', '>=', (float) $v));
        $request->whenFilled('selisih_max', fn ($v) => $query->where('snap.selisih', '<=', (float) $v));

        // has_npbg — npbg has no FK to items (Accurate mirror, matched by
        // kode_barang = items.code verbatim, confirmed 100% match in practice).
        if ($request->filled('has_npbg')) {
            $wantsNpbg = filter_var($request->input('has_npbg'), FILTER_VALIDATE_BOOL);
            $npbgCodes = fn ($q) => $q->select('kode_barang')->from('npbg')->whereNotNull('kode_barang');
            $wantsNpbg
                ? $query->whereIn('items.code', $npbgCodes)
                : $query->whereNotIn('items.code', $npbgCodes);
        }

        return $query;
    }
}
