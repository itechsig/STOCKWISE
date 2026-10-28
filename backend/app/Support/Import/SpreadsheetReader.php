<?php

namespace App\Support\Import;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Thin, read-only wrapper over PhpSpreadsheet for the STOCKWISE Excel imports.
 * Streams a single sheet, maps a header row to keys, yields associative rows.
 * A row/column LimitFilter keeps memory bounded against phantom rows.
 */
class SpreadsheetReader
{
    /** @var array<string, list<list<mixed>>> tiny memo so importers sharing a sheet don't reload it */
    private array $gridCache = [];

    /**
     * @param  int  $headerRow  1-based row holding column headers
     * @param  int|null  $firstDataRow  1-based first data row (defaults headerRow + 1)
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $path, string $sheet, int $headerRow = 1, ?int $firstDataRow = null): Collection
    {
        $firstDataRow ??= $headerRow + 1;
        $grid = $this->grid($path, $sheet);

        $headers = array_map(
            fn ($h) => is_string($h) ? trim($h) : ($h === null ? '' : (string) $h),
            $grid[$headerRow - 1] ?? []
        );

        $out = new Collection;

        foreach (array_slice($grid, $firstDataRow - 1) as $cells) {
            $row = [];
            $hasValue = false;

            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $value = $cells[$i] ?? null;
                $row[$key] = $value;
                $hasValue = $hasValue || $value !== null;
            }

            if ($hasValue) {
                $out->push($row);
            }
        }

        return $out;
    }

    /**
     * Raw 2D grid (0-indexed rows & cols), strings trimmed, blanks as null.
     *
     * @return list<list<mixed>>
     */
    public function grid(string $path, string $sheet, int $maxRow = 12000, int $maxColumn = 60): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        $cacheKey = "{$path}|{$sheet}|{$maxRow}|{$maxColumn}";
        if (isset($this->gridCache[$cacheKey])) {
            return $this->gridCache[$cacheKey];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new LimitFilter($maxRow, $maxColumn));
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheet)
            ?? throw new RuntimeException("Sheet '{$sheet}' not found in {$path}");

        $lastRow = min($worksheet->getHighestDataRow(), $maxRow);
        $lastCol = Coordinate::stringFromColumnIndex(
            min(Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()), $maxColumn)
        );

        // calculateFormulas: false -> use the cached values (data-only). Recalculating
        // thousands of spreadsheet formulas here is what made this hang.
        $grid = $worksheet->rangeToArray("A1:{$lastCol}{$lastRow}", null, false, false, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet, $reader);
        gc_collect_cycles();

        $trimmed = array_map(
            fn ($row) => array_map(
                fn ($c) => is_string($c) ? (trim($c) === '' ? null : trim($c)) : $c,
                $row
            ),
            $grid
        );

        // keep only the most recent sheet cached (importers read them in sequence)
        $this->gridCache = [$cacheKey => $trimmed];

        return $trimmed;
    }

    /**
     * Like grid(), but formula cells resolve to their cached (last-saved) value
     * instead of the formula string. Needed for sheets whose data columns are
     * formulas (e.g. the SAFETY STOCK sheets: SS = ROUNDUP(...)).
     *
     * @return list<list<mixed>>
     */
    public function gridCached(string $path, string $sheet, int $maxRow = 8000, int $maxColumn = 30): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Excel file not found: {$path}");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new LimitFilter($maxRow, $maxColumn));
        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheet)
            ?? throw new RuntimeException("Sheet '{$sheet}' not found in {$path}");

        $lastRow = min($worksheet->getHighestDataRow(), $maxRow);
        $lastColIdx = min(
            Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
            $maxColumn
        );

        $out = [];
        foreach ($worksheet->getRowIterator(1, $lastRow) as $row) {
            $cells = [];
            $it = $row->getCellIterator('A', Coordinate::stringFromColumnIndex($lastColIdx));
            $it->setIterateOnlyExistingCells(false);
            foreach ($it as $cell) {
                $v = $cell->getValue();
                if (is_string($v) && str_starts_with($v, '=')) {
                    $v = $cell->getOldCalculatedValue();
                }
                if (is_string($v)) {
                    $v = trim($v) === '' ? null : trim($v);
                }
                $cells[] = $v;
            }
            $out[] = $cells;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet, $reader);
        gc_collect_cycles();

        return $out;
    }

    /** @return list<string> sheet names in the workbook */
    public function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->listWorksheetNames($path);
    }
}
