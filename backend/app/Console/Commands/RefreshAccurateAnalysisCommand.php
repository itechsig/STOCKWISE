<?php

namespace App\Console\Commands;

use App\Services\Inventory\AccurateDerivedAnalysis;
use Illuminate\Console\Command;

/**
 * Fills stock / lead time / Safety Stock from Accurate data and recomputes the
 * inventory analysis (App\Services\Inventory\AccurateDerivedAnalysis). Runs
 * automatically after every successful sync (accurate:finish-batch); safe to
 * run again at any time (idempotent).
 */
class RefreshAccurateAnalysisCommand extends Command
{
    protected $signature = 'stockwise:refresh-from-accurate {--dry-run : Only report what would change}';

    protected $description = 'Stok awal, lead time & Safety Stock dari data Accurate, lalu hitung ulang analisis inventory (dashboard)';

    public function handle(AccurateDerivedAnalysis $analysis): int
    {
        set_time_limit(0);
        $report = $analysis->run((bool) $this->option('dry-run'));
        foreach ($report as $key => $value) {
            $this->line(str_pad($key, 38).': '.$value);
        }

        return self::SUCCESS;
    }
}
