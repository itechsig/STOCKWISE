<?php

namespace App\Support\Import\Importers;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Support\Str;

/**
 * People roster (Peminta / Pemeriksa / Dikeluarkan Oleh / Penempatan PIC).
 *
 * Sourced from the "Dropdown List" sheet of every tracking workbook — the
 * cleanest place these names appear. All rows land as `needs_review = true`
 * with no login user attached (NC-19 in docs/assumptions.md).
 */
class EmployeeImporter implements Importer
{
    /** @var list<array{file:string, columns:list<string>, hint:string}> */
    private const SOURCES = [
        ['file' => '1. PPB - RI.xlsx', 'columns' => ['Peminta'], 'hint' => 'peminta'],
        ['file' => '1. PPB - RI.xlsx', 'columns' => ['Pemeriksa'], 'hint' => 'pemeriksa'],
        ['file' => '2. NPBG.xlsx', 'columns' => ['Peminta'], 'hint' => 'peminta'],
        ['file' => '2. NPBG.xlsx', 'columns' => ['Dikeluarkan Oleh'], 'hint' => 'petugas_gudang'],
        ['file' => '3. Tracking Borrow & Lend.xlsx', 'columns' => ['Peminta'], 'hint' => 'peminta'],
        ['file' => '4. Tracking STPP.xlsx', 'columns' => ['Peminta'], 'hint' => 'peminta'],
    ];

    public function key(): string
    {
        return 'employees';
    }

    public function description(): string
    {
        return 'People roster from Excel dropdown lists (needs_review)';
    }

    public function dependsOn(): array
    {
        return ['departments'];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());

        /** @var array<string, string> $roster  normalized name => role hint */
        $roster = [];

        foreach (self::SOURCES as $src) {
            $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.$src['file'];

            if (! is_file($path)) {
                $result->note("Lewati {$src['file']} (tidak ditemukan)");

                continue;
            }

            try {
                $rows = $reader->rows($path, 'Dropdown List', 1);
            } catch (\Throwable $e) {
                $result->note("Gagal baca {$src['file']}/Dropdown List: {$e->getMessage()}");

                continue;
            }

            foreach ($src['columns'] as $column) {
                foreach ($rows->pluck($column)->filter() as $raw) {
                    $normalized = Employee::normalize((string) $raw);

                    if ($normalized === '' || $normalized === '-' || Str::startsWith($normalized, '=')) {
                        continue;
                    }

                    // keep the first hint we see for a name
                    $roster[$normalized] ??= $src['hint'];
                }
            }
        }

        ksort($roster);
        $result->read = count($roster);

        foreach ($roster as $normalized => $hint) {
            $employee = Employee::firstOrNew(['name_normalized' => $normalized, 'department_id' => null]);

            if ($employee->exists) {
                $result->skipped++;

                continue;
            }

            $employee->fill([
                'name' => Str::of($normalized)->title()->value(),
                'role_hint' => $hint,
                'is_active' => true,
                'needs_review' => true,
                'source' => 'excel:dropdown',
            ])->save();

            $result->created++;
        }

        if (Department::count() === 0) {
            $result->note('Belum ada department — employee tidak dipetakan ke divisi.');
        }

        return $result;
    }
}
