<?php

namespace App\Console\Commands\Accurate;

use App\Models\Item;
use App\Models\Unit;
use App\Services\Accurate\AccurateSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('accurate:import-new-items {--dry-run : Report counts without inserting anything}')]
#[Description('Insert Accurate items (accurate_item staging table) that have no matching Stockwise item by code, skipping Accurate\'s category nodes (AccurateSyncService::isProductRow).')]
class ImportNewItems extends Command
{
    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('accurate_item')) {
            $this->error('accurate_item table not found — run the sync-service staging sync first.');

            return self::FAILURE;
        }

        // the same rule as the sync: a category node has children or a category-shaped code
        $parents = DB::table('accurate_item')->whereNotNull('PARENTITEM')->distinct()->pluck('PARENTITEM')->map(fn ($p) => trim((string) $p))->flip();
        [$candidates, $placeholders] = DB::table('accurate_item as a')
            ->leftJoin('items as i', 'i.code', '=', 'a.ITEMNO')
            ->whereNull('i.id')
            ->select('a.ITEMNO', 'a.ITEMDESCRIPTION', 'a.UNIT1', 'a.SUSPENDED')
            ->get()
            ->partition(fn ($r) => trim((string) $r->ITEMNO) !== '' && AccurateSyncService::isProductRow(trim((string) $r->ITEMNO), $parents->has(trim((string) $r->ITEMNO))));
        $skippedPlaceholders = $placeholders->count();

        $this->info("Candidates to insert (products): {$candidates->count()}");
        $this->info("Skipped (category nodes): {$skippedPlaceholders}");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $unitsByCode = Unit::pluck('id', 'code')
            ->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));

        $created = 0;
        $skippedNoDescription = 0;

        DB::transaction(function () use ($candidates, $unitsByCode, &$created, &$skippedNoDescription) {
            foreach ($candidates as $row) {
                $description = trim((string) $row->ITEMDESCRIPTION);
                if ($description === '') {
                    $skippedNoDescription++;

                    continue;
                }

                $unitId = $unitsByCode->get(mb_strtoupper(trim((string) $row->UNIT1)));

                Item::create([
                    'code' => $row->ITEMNO,
                    'description' => $description,
                    'unit_id' => $unitId,
                    'item_type' => 'CONSUMABLE',
                    'needs_blueprint' => false,
                    'source' => 'accurate',
                    'is_active' => ! (bool) $row->SUSPENDED,
                    'accurate_synced_at' => now(),
                ]);
                $created++;
            }
        });

        $this->info("Created: {$created}");
        if ($skippedNoDescription > 0) {
            $this->warn("Skipped (empty description, would violate NOT NULL): {$skippedNoDescription}");
        }

        return self::SUCCESS;
    }
}
