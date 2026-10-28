<?php

namespace App\Support\Import\Importers;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Item master from DATA.xlsx / DATABASE UTAMA (~9.000 baris). Bulk upsert by
 * Kode Barang for speed.
 *
 * `SISA STOK (dd/mm/yyyy)` teks "STOK N PCS" -> OPENING_BALANCE movement bila angka
 * ada & gudang diketahui; kalau kosong -> inventory `stock_known = false`
 * (UNKNOWN, D2/NC-4).
 */
class ItemImporter implements Importer
{
    public function key(): string
    {
        return 'items';
    }

    public function description(): string
    {
        return 'Master barang + stok awal (DATA.xlsx DATABASE UTAMA)';
    }

    public function dependsOn(): array
    {
        return ['categories', 'units', 'warehouses'];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        if (! is_file($path)) {
            $result->note('DATA.xlsx tidak ditemukan.');

            return $result;
        }

        $categories = Category::pluck('id', 'path');
        $units = Unit::pluck('id', 'code');
        $warehouses = Warehouse::pluck('id', 'code');
        $locations = WarehouseLocation::query()->get()
            ->mapWithKeys(fn ($l) => [$l->warehouse_id.'|'.$l->code => $l->id]);

        $rows = $reader->rows($path, 'DATABASE UTAMA', 5);
        $result->read = $rows->count();

        $now = now();
        $upserts = [];
        /** @var array<string, array{warehouse_id:int, sisa:?float}> $stockByCode */
        $stockByCode = [];
        $seen = [];

        foreach ($rows as $row) {
            $code = Value::str($row['Kode Barang'] ?? null);
            $description = Value::str($row['Deskripsi Barang'] ?? null);

            if ($code === null || $description === null || isset($seen[$code])) {
                $result->skipped++;

                continue;
            }
            $seen[$code] = true;

            $segments = array_map('trim', array_values(array_filter([
                Value::str($row['Kategori Induk'] ?? null),
                Value::str($row['Kategori Anak 1'] ?? null),
                Value::str($row['Kategori Anak 2'] ?? null),
                Value::str($row['Kategori Anak 3'] ?? null),
            ])));
            $categoryId = $categories[implode(' > ', $segments)] ?? null;

            $unitCode = ($u = Value::str($row['UoM'] ?? null)) ? Str::upper(trim($u)) : null;
            $unitId = $unitCode ? ($units[$unitCode] ?? null) : null;

            $whCode = ($w = Value::str($row['LETAK GUDANG'] ?? null))
                ? Str::of($w)->squish()->upper()->value() : null;
            $warehouseId = $whCode ? ($warehouses[$whCode] ?? null) : null;

            $rakCode = ($r = Value::str($row['LETAK RAK'] ?? null))
                ? Str::of($r)->squish()->upper()->value() : null;
            $locationId = ($warehouseId && $rakCode)
                ? ($locations[$warehouseId.'|'.$rakCode] ?? null) : null;

            $leadTime = Value::int($row['LEAD TIME'] ?? null);

            $upserts[] = [
                'code' => $code,
                'description' => $description,
                'description_normalized' => Item::normalize($description),
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'item_type' => 'CONSUMABLE',
                'needs_blueprint' => Value::boolYaTidak($row['Perlu Blueprint?'] ?? null),
                'lead_time_days' => $leadTime !== null && $leadTime >= 0 && $leadTime <= 3650 ? $leadTime : null,
                'default_warehouse_id' => $warehouseId,
                'default_location_id' => $locationId,
                'blueprint_3d_ref' => Value::str($row['BLUEPRINT 3D VIEW'] ?? null),
                'source' => 'excel:DATA.xlsx',
                'is_active' => true,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($warehouseId) {
                $stockByCode[$code] = [
                    'warehouse_id' => $warehouseId,
                    'sisa' => Value::sisaStok($row['SISA STOK (22/08/2026)'] ?? $row['SISA STOK'] ?? null),
                ];
            }
        }

        foreach (array_chunk($upserts, 500) as $chunk) {
            Item::upsert(
                $chunk,
                ['code'],
                ['description', 'description_normalized', 'category_id', 'unit_id', 'needs_blueprint',
                    'lead_time_days', 'default_warehouse_id', 'default_location_id', 'blueprint_3d_ref',
                    'is_active', 'deleted_at', 'updated_at'],
            );
        }
        $result->created = count($upserts);

        $this->seedInventory($stockByCode, $now, $result);

        return $result;
    }

    /**
     * @param  array<string, array{warehouse_id:int, sisa:?float}>  $stockByCode
     */
    private function seedInventory(array $stockByCode, $now, ImportResult $result): void
    {
        $itemIds = Item::whereIn('code', array_keys($stockByCode))->pluck('id', 'code');

        $existingInv = DB::table('inventory')
            ->whereIn('item_id', $itemIds->values())
            ->get()->keyBy(fn ($r) => $r->item_id.'|'.$r->warehouse_id);

        $newInv = [];
        $openingMovements = [];
        $opening = 0;

        foreach ($stockByCode as $code => $info) {
            $itemId = $itemIds[$code] ?? null;
            if ($itemId === null) {
                continue;
            }
            $wid = $info['warehouse_id'];
            $key = $itemId.'|'.$wid;
            if (isset($existingInv[$key])) {
                continue;
            }

            $sisa = $info['sisa'];
            $known = $sisa !== null;
            $qty = $known && $sisa > 0 ? $sisa : 0;

            $newInv[$key] = [
                'item_id' => $itemId,
                'warehouse_id' => $wid,
                'actual_qty' => $qty,
                'reserved_qty' => 0,
                'stock_known' => $known,
                'last_counted_at' => $known ? $now : null,
                'last_movement_at' => $qty > 0 ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($qty > 0) {
                $opening++;
                $openingMovements[] = [
                    'item_id' => $itemId,
                    'warehouse_id' => $wid,
                    'movement_type' => 'OPENING_BALANCE',
                    'direction' => 1,
                    'qty' => $qty,
                    'actual_before' => 0,
                    'actual_after' => $qty,
                    'reserved_before' => 0,
                    'reserved_after' => 0,
                    'reference_type' => null,
                    'reference_id' => null,
                    'batch_uuid' => (string) Str::uuid(),
                    'note' => 'Opening balance (import DATA.xlsx)',
                    'created_by' => null,
                    'created_at' => $now,
                ];
            }
        }

        foreach (array_chunk(array_values($newInv), 500) as $chunk) {
            DB::table('inventory')->insert($chunk);
        }
        foreach (array_chunk($openingMovements, 500) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
        }

        $result->note("Opening balance dibuat untuk {$opening} item; sisanya stok = UNKNOWN.");
    }
}
