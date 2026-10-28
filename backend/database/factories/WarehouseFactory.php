<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('GUDANG ##')),
            'name' => $this->faker->company(),
            'is_active' => true,
        ];
    }
}
