<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryAnalyzer;
use Illuminate\Console\Command;

class StockwiseAnalyzeCommand extends Command
{
    protected $signature = 'stockwise:analyze';

    protected $description = 'Recompute the STOCKWISE inventory analysis (selisih/status/priority) and refresh snapshots';

    public function handle(InventoryAnalyzer $analyzer): int
    {
        $run = $analyzer->run();

        $this->info(sprintf(
            'Analisis selesai: %d item, %d TIDAK AMAN, threshold LT=%.1f, median defisit=%.1f',
            $run->item_count,
            $run->tidak_aman_count,
            $run->lead_time_threshold,
            $run->median_deficit,
        ));

        return self::SUCCESS;
    }
}
