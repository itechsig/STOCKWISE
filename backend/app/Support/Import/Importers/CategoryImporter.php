<?php

namespace App\Support\Import\Importers;

use App\Models\Category;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;

/**
 * 4-level category tree from DATA.xlsx / DATABASE UTAMA
 * (Kategori Induk > Kategori Anak 1 > Anak 2 > Anak 3). Levels 2–4 optional (NC-6).
 */
class CategoryImporter implements Importer
{
    private const COLUMNS = [
        1 => 'Kategori Induk',
        2 => 'Kategori Anak 1',
        3 => 'Kategori Anak 2',
        4 => 'Kategori Anak 3',
    ];

    public function key(): string
    {
        return 'categories';
    }

    public function description(): string
    {
        return 'Hierarki kategori 4 level (DATA.xlsx DATABASE UTAMA)';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        if (! is_file($path)) {
            $result->note('DATA.xlsx tidak ditemukan.');

            return $result;
        }

        $rows = $reader->rows($path, 'DATABASE UTAMA', 5);
        $result->read = $rows->count();

        /** @var array<string, Category> $cache  path => model */
        $cache = [];

        foreach ($rows as $row) {
            $parentId = null;
            $segments = [];

            foreach (self::COLUMNS as $level => $col) {
                $name = Value::str($row[$col] ?? null);
                if ($name === null) {
                    break;
                }
                $name = trim($name);
                $segments[] = $name;
                $path = implode(' > ', $segments);

                if (! isset($cache[$path])) {
                    $category = Category::firstOrNew(['parent_id' => $parentId, 'name' => $name]);
                    $wasNew = ! $category->exists;
                    $category->fill(['level' => $level, 'path' => $path, 'is_active' => true])->save();
                    $cache[$path] = $category;
                    $wasNew ? $result->created++ : $result->updated++;
                }

                $parentId = $cache[$path]->id;
            }
        }

        return $result;
    }
}
