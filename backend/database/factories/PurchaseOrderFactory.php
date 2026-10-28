<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\Site;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrder> */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return ['number' => 'BL/26/IX/'.$this->faker->unique()->numberBetween(1, 999), 'prefix' => 'BL', 'year' => 26, 'month' => 9, 'sequence' => $this->faker->unique()->numberBetween(1, 999), 'date' => now()->toDateString(), 'vendor_id' => Vendor::factory(), 'site_id' => Site::factory(), 'status' => 'DRAFT'];
    }
}
