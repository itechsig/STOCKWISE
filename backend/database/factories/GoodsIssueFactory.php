<?php

namespace Database\Factories;

use App\Models\GoodsIssue;
use App\Models\Site;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoodsIssue> */
class GoodsIssueFactory extends Factory
{
    protected $model = GoodsIssue::class;

    public function definition(): array
    {
        return [
            'number' => 'BKB/NA/26/IX/'.$this->faker->unique()->numberBetween(1, 999),
            'prefix' => 'NA',
            'year' => 26,
            'month' => 9,
            'sequence' => $this->faker->unique()->numberBetween(1, 999),
            'date' => now()->toDateString(),
            'type' => 'NON_PENJUALAN',
            'classification' => 'UMUM',
            'site_id' => Site::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'PREPARING',
        ];
    }
}
