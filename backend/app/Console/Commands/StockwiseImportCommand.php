<?php

namespace App\Console\Commands;

use App\Support\Import\Importer;
use App\Support\Import\Importers\CategoryImporter;
use App\Support\Import\Importers\DepartmentImporter;
use App\Support\Import\Importers\EmployeeImporter;
use App\Support\Import\Importers\ItemImporter;
use App\Support\Import\Importers\ItemSafetyStockImporter;
use App\Support\Import\Importers\UnitImporter;
use App\Support\Import\Importers\WarehouseImporter;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StockwiseImportCommand extends Command
{
    protected $signature = 'stockwise:import
        {targets?* : Importer keys to run (default: all, in dependency order)}
        {--path= : Override stockwise.import_path (folder with the .xlsx files)}
        {--list : List available importers and exit}';

    protected $description = 'Import company Excel data (docs/excel-data-mapping.md) into the database';

    /** @return list<class-string<Importer>> */
    private function registered(): array
    {
        return [
            DepartmentImporter::class,
            EmployeeImporter::class,
            UnitImporter::class,
            CategoryImporter::class,
            WarehouseImporter::class,
            ItemImporter::class,
            ItemSafetyStockImporter::class,
        ];
    }

    public function handle(SpreadsheetReader $reader): int
    {
        // Parsing the company .xlsx files (up to 8 MB, thousands of phantom rows)
        // needs headroom beyond the default 128M.
        if ((int) filter_var(ini_get('memory_limit'), FILTER_SANITIZE_NUMBER_INT) < 512) {
            ini_set('memory_limit', '1024M');
        }

        if ($path = $this->option('path')) {
            config(['stockwise.import_path' => $path]);
        }

        $importPath = config('stockwise.import_path');

        /** @var array<string, Importer> $importers */
        $importers = collect($this->registered())
            ->map(fn (string $class) => app($class))
            ->keyBy(fn (Importer $i) => $i->key())
            ->all();

        if ($this->option('list')) {
            $this->table(
                ['key', 'depends on', 'description'],
                collect($importers)->map(fn (Importer $i) => [
                    $i->key(), implode(', ', $i->dependsOn()) ?: '-', $i->description(),
                ]),
            );

            return self::SUCCESS;
        }

        if (! is_dir($importPath)) {
            $this->error("import_path tidak ditemukan: {$importPath}");
            $this->line('Set STOCKWISE_IMPORT_PATH di .env atau pakai --path=');

            return self::FAILURE;
        }

        $requested = $this->argument('targets') ?: array_keys($importers);
        $order = $this->resolveOrder($requested, $importers);

        $this->info("import_path: {$importPath}");
        $rows = [];

        foreach ($order as $key) {
            $importer = $importers[$key];
            $this->line("→ {$key} — {$importer->description()}");

            try {
                $result = DB::transaction(fn () => $importer->import($reader));
            } catch (\Throwable $e) {
                $this->error("  {$key} gagal: {$e->getMessage()}");

                return self::FAILURE;
            }

            $rows[] = [$result->target, $result->read, $result->created, $result->updated, $result->skipped];

            foreach ($result->notes as $note) {
                $this->warn("  • {$note}");
            }
        }

        $this->newLine();
        $this->table(['target', 'read', 'created', 'updated', 'skipped'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $requested
     * @param  array<string, Importer>  $importers
     * @return list<string>
     */
    private function resolveOrder(array $requested, array $importers): array
    {
        $order = [];

        $visit = function (string $key) use (&$visit, &$order, $importers): void {
            if (in_array($key, $order, true)) {
                return;
            }
            if (! isset($importers[$key])) {
                throw new \InvalidArgumentException("Importer tidak dikenal: {$key}");
            }
            foreach ($importers[$key]->dependsOn() as $dep) {
                $visit($dep);
            }
            $order[] = $key;
        };

        foreach ($requested as $key) {
            $visit($key);
        }

        return $order;
    }
}
