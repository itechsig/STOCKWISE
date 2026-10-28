<?php

namespace App\Support\Import\Importers;

use App\Models\Item;
use App\Models\ItemSafetyStock;
use App\Support\Import\Importer;
use App\Support\Import\ImportResult;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\Value;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Safety stock / lead time / avg-usage from the 12 "SAFETY STOCK *" sheets of
 * DATA.xlsx. Matched to items by normalized description. Sheets have inconsistent
 * header positions, so the header row is detected by scanning for "ITEM DESCRIPTION".
 *
 * Conflict (same description in >1 sheet): keep every row, mark the largest SS
 * `is_effective`, flag the rest `needs_review` (NC-7 / D4).
 */
class ItemSafetyStockImporter implements Importer
{
    public function key(): string
    {
        return 'item_safety_stocks';
    }

    public function description(): string
    {
        return 'Safety stock per kategori (DATA.xlsx SAFETY STOCK *)';
    }

    public function dependsOn(): array
    {
        return ['items'];
    }

    public function import(SpreadsheetReader $reader): ImportResult
    {
        $result = new ImportResult($this->key());
        $path = config('stockwise.import_path').DIRECTORY_SEPARATOR.config('stockwise.files.master');

        if (! is_file($path)) {
            $result->note('DATA.xlsx tidak ditemukan.');

            return $result;
        }

        $sheets = array_values(array_filter(
            $reader->sheetNames($path),
            fn ($name) => Str::startsWith($name, 'SAFETY STOCK')
        ));

        // items: normalized description -> id  (single query, no models)
        $itemsByNorm = DB::table('items')->select('id', 'description_normalized')->get()
            ->mapWithKeys(fn ($r) => [$r->description_normalized => $r->id]);

        ItemSafetyStock::query()->delete();

        $now = now();
        $unmatched = 0;
        $batch = [];

        foreach ($sheets as $sheet) {
            $grid = $reader->gridCached($path, $sheet, maxRow: 6100, maxColumn: 26);
            $map = $this->columnMap($grid);

            if ($map === null) {
                $result->note("Sheet '{$sheet}': kolom header tidak dikenali — dilewati.");

                continue;
            }

            $category = trim(Str::after($sheet, 'SAFETY STOCK'));

            foreach (array_slice($grid, $map['headerRow'] + 1) as $cells) {
                $desc = Value::str($cells[$map['desc']] ?? null);
                if ($desc === null) {
                    continue;
                }
                $result->read++;

                $itemId = $itemsByNorm[Item::normalize($desc)] ?? null;
                if ($itemId === null) {
                    $unmatched++;

                    continue;
                }

                $ss = $this->cell($cells, $map['ss']) ?? 0;
                $minPr = $this->cell($cells, $map['minpr']);

                // Skip rows that carry no real signal (an item appears in most sheets
                // with SS/MIN PR = 0 for categories it doesn't belong to).
                if ($ss <= 0 && ($minPr === null || $minPr <= 0)) {
                    $result->skipped++;

                    continue;
                }

                $batch[] = [
                    'item_id' => $itemId,
                    'source_category' => $category,
                    'period_label' => 'Agt2025-Jul2026',
                    'avg_usage_1m' => $this->cell($cells, $map['avg1']),
                    'avg_usage_3m' => $this->cell($cells, $map['avg3']),
                    'avg_usage_6m' => $this->cell($cells, $map['avg6']),
                    'avg_usage_12m' => $this->cell($cells, $map['avg12']),
                    'lead_time_days' => ($lt = $this->cell($cells, $map['lt'])) !== null ? (int) round($lt) : null,
                    'sqrt_lt' => $this->cell($cells, $map['sqrtlt']),
                    'safety_stock' => $ss,
                    'min_pr' => $minPr,
                    'is_effective' => false,
                    'needs_review' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($batch) >= 1000) {
                    ItemSafetyStock::insert($batch);
                    $result->created += count($batch);
                    $batch = [];
                }
            }

            unset($grid);
            gc_collect_cycles();
        }

        if ($batch !== []) {
            ItemSafetyStock::insert($batch);
            $result->created += count($batch);
        }

        if ($unmatched > 0) {
            $result->note("{$unmatched} baris SS tidak cocok ke item — diabaikan.");
        }

        $this->resolveEffective($result);

        return $result;
    }

    private function cell(array $cells, ?int $idx): ?float
    {
        return $idx === null ? null : Value::float($cells[$idx] ?? null);
    }

    /**
     * @param  list<list<mixed>>  $grid
     * @return array<string, int|null>|null
     */
    private function columnMap(array $grid): ?array
    {
        foreach (array_slice($grid, 0, 8) as $r => $cells) {
            $desc = $ss = null;
            foreach ($cells as $c => $val) {
                if (! is_string($val)) {
                    continue;
                }
                $u = Str::upper(trim($val));
                if ($desc === null && Str::contains($u, 'ITEM DESCRIPTION')) {
                    $desc = $c;
                }
                if ($ss === null && $u === 'SS') {
                    $ss = $c;
                }
            }
            if ($desc === null || $ss === null) {
                continue;
            }

            $find = function (callable $pred) use ($cells): ?int {
                foreach ($cells as $c => $val) {
                    if (is_string($val) && $pred(Str::upper(trim($val)))) {
                        return $c;
                    }
                }

                return null;
            };
            $rata = $find(fn ($v) => Str::contains($v, 'RATA RATA'));

            return [
                'headerRow' => $r,
                'desc' => $desc,
                'ss' => $ss,
                'minpr' => $find(fn ($v) => Str::contains($v, 'MIN PR')),
                'lt' => $find(fn ($v) => $v === 'LT'),
                'sqrtlt' => $find(fn ($v) => str_ends_with($v, 'LT') && $v !== 'LT' && mb_strlen($v) <= 5),
                'avg1' => $rata,
                'avg3' => $rata !== null ? $rata + 1 : null,
                'avg6' => $rata !== null ? $rata + 2 : null,
                'avg12' => $rata !== null ? $rata + 3 : null,
            ];
        }

        return null;
    }

    private function resolveEffective(ImportResult $result): void
    {
        // Effective row per item = highest safety_stock (tie -> lowest id).
        $byItem = DB::table('item_safety_stocks')
            ->select('id', 'item_id', 'safety_stock')
            ->orderBy('item_id')->orderByDesc('safety_stock')->orderBy('id')
            ->get()
            ->groupBy('item_id');

        $effectiveIds = [];
        $conflicts = 0;
        $reviewIds = [];

        foreach ($byItem as $rows) {
            $effectiveIds[] = $rows->first()->id;
            if ($rows->count() > 1) {
                $conflicts++;
                foreach ($rows->slice(1) as $r) {
                    $reviewIds[] = $r->id;
                }
            }
        }

        foreach (array_chunk($effectiveIds, 1000) as $chunk) {
            DB::table('item_safety_stocks')->whereIn('id', $chunk)->update(['is_effective' => true]);
        }
        foreach (array_chunk($reviewIds, 1000) as $chunk) {
            DB::table('item_safety_stocks')->whereIn('id', $chunk)->update(['needs_review' => true]);
        }

        $result->note("{$conflicts} item punya SS dari >1 sheet (yang tidak efektif ditandai needs_review).");
    }
}
