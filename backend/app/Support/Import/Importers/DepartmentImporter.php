<?php

namespace App\Support\Import\Importers;

use App\Models\Department;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Support\Str;

/**
 * Divisi / Department master.
 *
 * Base list is the canonical set found during Phase 0 Excel analysis
 * (docs/step1-analysis.md §Katalog enum "Divisi"); the importer additionally
 * picks up any `Divisi` value present in the PPB-RI and NPBG "Dropdown List"
 * sheets so nothing from the source files is silently dropped.
 */
class DepartmentImporter implements Importer
{
    private const CANONICAL = [
        'ADMIN TABUNG', 'AKUNTING', 'DIREKTUR', 'DISTRIBUSI', 'DRIVER',
        'GENERAL SERVICE', 'GUDANG', 'INVENTORY', 'IT DEVELOPER', 'MAINTENANCE',
        'MARKETING', 'MGR. OPERASIONAL', 'SALES COUNTER', 'SECURITY',
    ];

    /** @var list<array{file:string, sheet:string, column:string}> */
    private const SOURCES = [
        ['file' => '1. PPB - RI.xlsx', 'sheet' => 'Dropdown List', 'column' => 'Divisi'],
        ['file' => '2. NPBG.xlsx', 'sheet' => 'Dropdown List', 'column' => 'Divisi'],
        // STPP "Penempatan" is a placement, not a division (NC-18) — kept out of the
        // department master; STPP will store it as placement_raw in a later phase.
    ];

    public function key(): string
    {
        return 'departments';
    }

    public function description(): string
    {
        return 'Divisi / department master (canonical + Excel dropdown lists)';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());

        $names = collect(self::CANONICAL);

        foreach (self::SOURCES as $src) {
            $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.$src['file'];

            if (! is_file($path)) {
                $result->note("Lewati {$src['file']} (tidak ditemukan di import_path)");

                continue;
            }

            try {
                $values = $reader->rows($path, $src['sheet'], 1)
                    ->pluck($src['column'])
                    ->filter()
                    ->map(fn ($v) => Str::of((string) $v)->squish()->upper()->value())
                    ->filter();

                $names = $names->concat($values);
            } catch (\Throwable $e) {
                $result->note("Gagal baca {$src['file']}/{$src['sheet']}: {$e->getMessage()}");
            }
        }

        $names = $names
            ->reject(fn (string $n) => $n === '' || $n === '-' || Str::startsWith($n, '=') || mb_strlen($n) > 100)
            ->unique()
            ->sort()
            ->values();

        $result->read = $names->count();

        foreach ($names as $name) {
            $department = Department::firstOrNew(['name' => $name]);

            if (! $department->exists) {
                $department->fill(['is_active' => true])->save();
                $result->created++;
            } else {
                $result->skipped++;
            }
        }

        return $result;
    }
}
