<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Moves the tracking Excel files of "1. Dashboard Input Data" (and their DATA BUKTI
 * photos) into STOCKWISE — after this the folder is no longer needed. Re-runnable.
 */
class ImportTrackingExcelCommand extends Command
{
    protected $signature = 'stockwise:import-tracking
        {module : maintenance | stpp | tyre | used-return | lend-borrow}
        {--dir= : Folder "1. Dashboard Input Data" (default: di samping folder backend)}
        {--dry-run : Hanya hitung, tidak menyimpan}';

    protected $description = 'Pindahkan data + foto tracking dari folder Dashboard Input Data ke STOCKWISE';

    /** module => [importer class, Excel file, DATA BUKTI sub-folder] */
    private const MODULES = [
        'maintenance' => [\App\Services\Tracking\Import\MaintenanceExcelImporter::class, '6. Tracking Maintenance Assets.xlsx', 'Foto - Maintenance Assets'],
        'stpp' => [\App\Services\Tracking\Import\StppExcelImporter::class, '4. Tracking STPP.xlsx', 'Foto - Tracking STPP'],
        'tyre' => [\App\Services\Tracking\Import\TyreExcelImporter::class, '5. Tracking Ban Luar.xlsx', 'Foto - Tracking Ban Luar'],
        'used-return' => [\App\Services\Tracking\Import\UsedReturnExcelImporter::class, '8. Tracking Pengembalian Bekas.xlsx', 'Foto - Pengembalian Bekas'],
        'lend-borrow' => [\App\Services\Tracking\Import\LendBorrowExcelImporter::class, '3. Tracking Borrow & Lend.xlsx', 'Foto - Tracking Borrow & Lend'],
    ];

    public function handle(): int
    {
        $module = $this->argument('module');
        if (! isset(self::MODULES[$module])) {
            $this->error('Modul tidak dikenal. Pilih: '.implode(', ', array_keys(self::MODULES)));

            return self::FAILURE;
        }
        [$class, $file, $photos] = self::MODULES[$module];
        if (! class_exists($class)) {
            $this->error("Importer {$module} belum tersedia.");

            return self::FAILURE;
        }
        $dir = rtrim($this->option('dir') ?: base_path('../1. Dashboard Input Data'), '/\\');
        $xlsx = "{$dir}/{$file}";
        if (! is_file($xlsx)) {
            $this->error("File tidak ditemukan: {$xlsx}");

            return self::FAILURE;
        }
        $photoDir = is_dir("{$dir}/DATA BUKTI/{$photos}") ? "{$dir}/DATA BUKTI/{$photos}" : null;
        if (! $photoDir) {
            $this->warn("Folder foto tidak ditemukan: {$dir}/DATA BUKTI/{$photos} — foto dilewati.");
        }

        $st = app($class)->import($xlsx, $photoDir, (bool) $this->option('dry-run'));
        foreach ($st as $k => $v) {
            if (is_array($v)) {
                $this->line(sprintf('%-18s %d', $k, count($v)).($v ? '  e.g. '.implode(', ', array_slice($v, 0, 8)) : ''));
            } else {
                $this->line(sprintf('%-18s %s', $k, $v));
            }
        }
        $this->info($this->option('dry-run') ? 'Dry run — tidak ada yang disimpan.' : 'Selesai.');

        return self::SUCCESS;
    }
}
