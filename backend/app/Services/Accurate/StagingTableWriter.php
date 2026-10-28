<?php

namespace App\Services\Accurate;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writes one Agent-pushed Accurate table dump into its `accurate_*` MySQL
 * mirror table. Replaces sync-service/database/mysql_client.py's job — the
 * Agent no longer touches MySQL directly (brief §6), it POSTs structured
 * column metadata + rows here instead.
 *
 * Every input is checked against config('accurate.mirror_tables') /
 * config('accurate.column_types') before it ever reaches a DDL or SQL
 * statement — this is the only place in the app that builds table/column
 * names from a network payload, so it is deliberately strict: no name or
 * type outside the whitelist is ever interpolated, even quoted.
 */
class StagingTableWriter
{
    protected const NAME_PATTERN = '/^[A-Z0-9_]+$/i';

    protected const MAX_VARCHAR = 16000; // matches type_mapping.py's MYSQL_MAX_VARCHAR

    /**
     * @return string the resolved MySQL table name (accurate_<table>)
     */
    public function tableNameFor(string $accurateTable): string
    {
        $table = mb_strtoupper(trim($accurateTable));

        if (! in_array($table, config('accurate.mirror_tables', []), true)) {
            throw new InvalidArgumentException("'{$accurateTable}' is not a whitelisted Accurate mirror table.");
        }

        return 'accurate_'.mb_strtolower($table);
    }

    /**
     * (Re)create the mirror table for the first chunk of a staging session,
     * matching sync-service/database/mysql_client.py::create_mirror_table's
     * DROP+CREATE-from-scratch semantics — a staging refresh is a full
     * mirror, not an incremental upsert (the business-logic layer downstream
     * in AccurateSyncService is what does the real, safe upsert into
     * Stockwise's own tables).
     *
     * @param  array<int, array{name: string, type: string, length: ?int, nullable: bool}>  $columns
     * @param  array<int, string>  $primaryKey
     */
    public function createTable(string $accurateTable, array $columns, array $primaryKey = []): void
    {
        $mysqlTable = $this->tableNameFor($accurateTable);
        $columnNames = array_map(fn ($c) => $c['name'], $columns);

        $defs = array_map(fn ($c) => $this->columnDefinition($c), $columns);

        foreach ($primaryKey as $pk) {
            if (! in_array($pk, $columnNames, true)) {
                throw new InvalidArgumentException("Primary key column '{$pk}' is not among the supplied columns.");
            }
        }

        if ($primaryKey !== []) {
            $pkCols = implode(', ', array_map(fn ($c) => "`{$c}`", $primaryKey));
            $defs[] = "PRIMARY KEY ({$pkCols})";
        }

        // MySQL-only table options — omitted on other drivers so the test
        // suite (SQLite in-memory, see phpunit.xml) can exercise this same
        // code path; production always runs on MySQL (config/database.php).
        $options = DB::connection()->getDriverName() === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

        DB::statement("DROP TABLE IF EXISTS `{$mysqlTable}`");
        DB::statement(
            "CREATE TABLE `{$mysqlTable}` (\n  ".implode(",\n  ", $defs)."\n){$options}"
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  each row keyed by column name
     */
    public function insertRows(string $accurateTable, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $mysqlTable = $this->tableNameFor($accurateTable);
        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($mysqlTable)->insert($chunk);
            $inserted += count($chunk);
        }

        return $inserted;
    }

    /**
     * @param  array{name: string, type: string, length: ?int, scale?: ?int, nullable: bool}  $column
     */
    protected function columnDefinition(array $column): string
    {
        $name = $column['name'] ?? '';
        $type = mb_strtoupper($column['type'] ?? '');
        $length = $column['length'] ?? null;
        $nullable = (bool) ($column['nullable'] ?? true);

        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid column name '{$name}'.");
        }

        if (! in_array($type, config('accurate.column_types', []), true)) {
            throw new InvalidArgumentException("Column '{$name}' has unsupported type '{$type}'.");
        }

        $sqlType = match ($type) {
            'VARCHAR' => $this->boundedLength($length) > 0 && $this->boundedLength($length) <= self::MAX_VARCHAR
                ? 'VARCHAR('.$this->boundedLength($length).')'
                : 'TEXT',
            'CHAR' => $this->boundedLength($length) > 0 && $this->boundedLength($length) <= 255
                ? 'CHAR('.$this->boundedLength($length).')'
                : 'TEXT',
            // exact precision / scale of the Firebird NUMERIC — never rounded to an integer
            'DECIMAL' => $this->decimalType($length, $column['scale'] ?? null, $name),
            default => $type,
        };

        return "`{$name}` {$sqlType} ".($nullable ? 'NULL' : 'NOT NULL');
    }

    protected function decimalType(mixed $precision, mixed $scale, string $name): string
    {
        $p = is_numeric($precision) ? (int) $precision : 18;
        $s = is_numeric($scale) ? (int) $scale : 0;
        if ($p < 1 || $p > 65 || $s < 0 || $s > 30 || $s > $p) {
            throw new InvalidArgumentException("Column '{$name}' has an invalid DECIMAL({$p},{$s}).");
        }

        return "DECIMAL({$p},{$s})";
    }

    protected function boundedLength(mixed $length): int
    {
        return is_numeric($length) ? max(0, (int) $length) : 0;
    }
}
