<?php

namespace Database\Factories;

use App\Models\Receiving;
use App\Models\Site;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Receiving> */
class ReceivingFactory extends Factory
{
    protected $model = Receiving::class;

    public function definition(): array
    {
        return ['number' => 'RI/NV/26/IX/'.$this->faker->unique()->numberBetween(1, 999), 'prefix' => 'NV', 'year' => 26, 'month' => 9, 'sequence' => $this->faker->unique()->numberBetween(1, 999), 'date' => now()->toDateString(), 'source_type' => 'PURCHASE', 'warehouse_id' => Warehouse::factory(), 'site_id' => Site::factory(), 'status' => 'CHECKING'];
    }
}
