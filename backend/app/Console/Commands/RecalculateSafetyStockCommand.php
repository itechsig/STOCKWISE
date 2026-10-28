<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Services\Inventory\InventoryAnalyzer;
use App\Services\Inventory\SafetyStockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stockwise:recalculate-safety-stock {--dry-run : Show before/after without saving} {--analyze : Also recompute inventory_snapshots afterward}')]
#[Description('Refreshes avg_usage_1m/3m/6m/12m from real NPBG (Accurate) history for every item with an effective Safety Stock row, then reapplies the Safety Stock/MIN PR formula (SafetyStockService::calculate()).')]
class RecalculateSafetyStockCommand extends Command
{
    public function handle(SafetyStockService $service, InventoryAnalyzer $analyzer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $usageByCode = $service->usageAveragesFromNpbg();

        $rows = ItemSafetyStock::where('is_effective', true)->with('item:id,code')->get();
        $this->info("Effective Safety Stock rows to recalculate: {$rows->count()}");

        $changed = 0;
        $table = [];

        foreach ($rows as $row) {
            $code = $row->item?->code;
            $usage = $code ? ($usageByCode[$code] ?? null) : null;

            // No NPBG history at all for this item ("barang tanpa pengeluaran") —
            // per explicit instruction, treated as 0, never guessed.
            $newAvg = $usage ?? ['avg_usage_1m' => 0.0, 'avg_usage_3m' => 0.0, 'avg_usage_6m' => 0.0, 'avg_usage_12m' => 0.0];

            // avg_usage_3m drives the formula (see SafetyStockService docblock).
            $before = ['avg_usage_3m' => $row->avg_usage_3m, 'safety_stock' => $row->safety_stock, 'min_pr' => $row->min_pr];

            if (! $dryRun) {
                $service->update($row, $newAvg);
            } else {
                $preview = SafetyStockService::calculate($newAvg['avg_usage_3m'], $row->lead_time_days);
                $row = (object) array_merge($newAvg, $preview, ['item' => $row->item]);
            }

            $after = ['avg_usage_3m' => $row->avg_usage_3m, 'safety_stock' => $row->safety_stock, 'min_pr' => $row->min_pr];

            if ($before['safety_stock'] != $after['safety_stock'] || $before['min_pr'] != $after['min_pr'] || $before['avg_usage_3m'] != $after['avg_usage_3m']) {
                $changed++;
                if (count($table) < 20) {
                    $table[] = [
                        $code,
                        $before['avg_usage_3m'], $after['avg_usage_3m'],
                        $before['safety_stock'], $after['safety_stock'],
                        $before['min_pr'], $after['min_pr'],
                    ];
                }
            }
        }

        $this->table(
            ['Kode', 'avg_3m before', 'avg_3m after', 'SS before', 'SS after', 'MIN PR before', 'MIN PR after'],
            $table
        );
        $this->info(($dryRun ? '[DRY RUN] Would change' : 'Changed').": {$changed} of {$rows->count()} rows.");

        if (! $dryRun && $this->option('analyze')) {
            $run = $analyzer->run();
            $this->info("inventory_snapshots recomputed: {$run->item_count} item, {$run->tidak_aman_count} TIDAK_AMAN.");
        }

        return self::SUCCESS;
    }
}
