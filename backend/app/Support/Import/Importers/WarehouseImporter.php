<?php

namespace App\Support\Import\Importers;

use App\Models\Site;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;
use Illuminate\Support\Str;

/**
 * Warehouses (LETAK GUDANG) + racks (LETAK RAK) from DATA.xlsx / DATABASE UTAMA.
 * Trailing-space variants ("GUDANG 1 " vs "GUDANG 1") are trimmed & merged (D17).
 * All under site SIG-SDA (NC-5).
 */
class WarehouseImporter implements Importer
{
    public function key(): string
    {
        return 'warehouses';
    }

    public function description(): string
    {
        return 'Gudang + rak (DATA.xlsx DATABASE UTAMA)';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        $site = Site::firstOrCreate(
            ['code' => 'SIG-SDA'],
            ['name' => 'SIG Sidoarjo', 'is_inventory_managed' => true, 'is_active' => true]
        );

        if (! is_file($path)) {
            $result->note('DATA.xlsx tidak ditemukan.');

            return $result;
        }

        $rows = $reader->rows($path, 'DATABASE UTAMA', 5);
        $result->read = $rows->count();

        /** @var array<string, int> $warehouseIds */
        $warehouseIds = [];

        foreach ($rows as $row) {
            $whName = Value::str($row['LETAK GUDANG'] ?? null);
            if ($whName === null) {
                continue;
            }
            $whName = Str::of($whName)->squish()->upper()->value();

            if (! isset($warehouseIds[$whName])) {
                $wh = Warehouse::firstOrNew(['site_id' => $site->id, 'code' => $whName]);
                if (! $wh->exists) {
                    $wh->fill(['name' => $whName, 'is_active' => true])->save();
                    $result->created++;
                }
                $warehouseIds[$whName] = $wh->id;
            }

            $rak = Value::str($row['LETAK RAK'] ?? null);
            if ($rak !== null) {
                $rak = Str::of($rak)->squish()->upper()->value();
                WarehouseLocation::firstOrCreate(
                    ['warehouse_id' => $warehouseIds[$whName], 'code' => $rak],
                    ['is_active' => true]
                );
            }
        }

        $result->note(sprintf('%d gudang, %d rak', count($warehouseIds), WarehouseLocation::count()));

        return $result;
    }
}
