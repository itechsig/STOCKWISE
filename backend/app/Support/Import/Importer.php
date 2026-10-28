<?php

namespace App\Support\Import;

interface Importer
{
    /** Machine key, e.g. "departments", "employees", "items". */
    public function key(): string;

    /** Human description shown by the console command. */
    public function description(): string;

    /**
     * Import keys this one depends on (must run first).
     *
     * @return list<string>
     */
    public function dependsOn(): array;

    public function import(SpreadsheetReader $reader): ImportResult;
}
