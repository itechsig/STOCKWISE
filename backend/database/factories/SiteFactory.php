<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Site> */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('SIG-???')),
            'name' => $this->faker->city(),
            'is_inventory_managed' => true,
            'is_active' => true,
        ];
    }
}
