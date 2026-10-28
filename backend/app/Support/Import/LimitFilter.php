<?php

namespace App\Support\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Caps how many rows/columns PhpSpreadsheet loads — keeps memory bounded on the
 *  "phantom rows" the company spreadsheets carry. */
class LimitFilter implements IReadFilter
{
    public function __construct(
        private readonly int $maxRow = 12000,
        private readonly int $maxColumn = 60,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row > $this->maxRow) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumn;
    }
}
