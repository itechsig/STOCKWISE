<?php

namespace App\Console\Commands;

use App\Services\Tracking\ManufacturingExcelImporter;
use Illuminate\Console\Command;

/** Loads "7. Tracking Manufaktur & Assembly.xlsx" into Manufaktur & Assembly (re-runnable). */
class ImportManufacturingExcelCommand extends Command
{
    protected $signature = 'stockwise:import-manufacturing
        {path? : Path ke file Excel (default: 7. Tracking Manufaktur & Assembly.xlsx di folder proyek)}
        {--dry-run : Hanya hitung, tidak menyimpan}
        {--photos= : Folder foto (DATA BUKTI/Foto - Manufaktur & Assembly); default dari folder Dashboard Input Data}
        {--photos-only : Hanya hubungkan foto, tanpa membaca Excel}';

    protected $description = 'Isi data Manufaktur & Assembly (sheet MA dan MJ) dari file Excel tracking';

    public function handle(ManufacturingExcelImporter $importer): int
    {
        $path = $this->argument('path') ?: base_path('../7. Tracking Manufaktur & Assembly.xlsx');
        if (! $this->option('photos-only') && ! is_file($path)) {
            $this->error("File tidak ditemukan: {$path}");

            return self::FAILURE;
        }
        if (! $this->option('photos-only')) {
            $stats = $importer->import($path, (bool) $this->option('dry-run'));
            foreach ($stats as $sheet => $s) {
                $this->line($sheet.': '.json_encode($s));
            }
        }
        $photos = $this->option('photos') ?: base_path('../1. Dashboard Input Data/DATA BUKTI/Foto - Manufaktur & Assembly');
        if (is_dir($photos)) {
            $p = $importer->linkPhotos($photos, (bool) $this->option('dry-run'));
            $this->line('Foto: '.json_encode(['rows_with_name' => $p['rows_with_name'], 'linked' => $p['linked'], 'already' => $p['already'], 'missing' => count($p['missing'])]));
            foreach (array_slice($p['missing'], 0, 20) as $m) {
                $this->warn("  foto tidak ditemukan: {$m}");
            }
        } else {
            $this->warn("Folder foto tidak ditemukan: {$photos} — foto dilewati.");
        }
        $this->info($this->option('dry-run') ? 'Dry run — tidak ada yang disimpan.' : 'Selesai.');

        return self::SUCCESS;
    }
}
