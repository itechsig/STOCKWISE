<?php

namespace App\Support\Import\Importers;

use App\Models\Unit;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;
use Illuminate\Support\Str;

/**
 * UoM master from DATA.xlsx / DATABASE UTAMA (kolom "UoM"), plus a canonical set
 * of names for the common codes.
 */
class UnitImporter implements Importer
{
    private const NAMES = [
        'PCS' => 'Pieces', 'SET' => 'Set', 'BOX' => 'Box', 'BK' => 'Buku', 'PCK' => 'Pack',
        'LTR' => 'Liter', 'GLN' => 'Galon', 'KG' => 'Kilogram', 'MTR' => 'Meter',
        'BTG' => 'Batang', 'BTL' => 'Botol', 'ROLL' => 'Roll', 'ROL' => 'Roll',
        'RIM' => 'Rim', 'DUS' => 'Dus', 'KLG' => 'Kaleng', 'JRG' => 'Jerigen',
        'LBR' => 'Lembar', 'PSG' => 'Pasang', 'TBG' => 'Tabung', 'SCK' => 'Sak',
        'M2' => 'Meter persegi', 'M3' => 'Meter kubik', 'CM' => 'Centimeter',
        'CM2' => 'Centimeter persegi', 'CMS' => 'Centimeter', 'C M' => 'Centimeter',
    ];

    public function key(): string
    {
        return 'units';
    }

    public function description(): string
    {
        return 'Satuan / UoM (DATA.xlsx DATABASE UTAMA)';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        $codes = collect(array_keys(self::NAMES));

        if (is_file($path)) {
            try {
                $codes = $codes->concat(
                    $reader->rows($path, 'DATABASE UTAMA', 5)
                        ->pluck('UoM')
                        ->map(fn ($v) => Value::str($v))
                        ->filter()
                        ->map(fn ($v) => Str::upper(trim($v)))
                );
            } catch (\Throwable $e) {
                $result->note("Gagal baca DATABASE UTAMA: {$e->getMessage()}");
            }
        } else {
            $result->note('DATA.xlsx tidak ditemukan — hanya seed kode kanonik.');
        }

        $codes = $codes->reject(fn ($c) => $c === null || $c === '' || mb_strlen($c) > 15)
            ->unique()->sort()->values();

        $result->read = $codes->count();

        foreach ($codes as $code) {
            $unit = Unit::firstOrNew(['code' => $code]);
            if ($unit->exists) {
                $result->skipped++;

                continue;
            }
            $unit->fill(['name' => self::NAMES[$code] ?? $code, 'is_active' => true])->save();
            $result->created++;
        }

        return $result;
    }
}
