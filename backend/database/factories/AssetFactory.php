<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'code' => 'W '.$this->faker->unique()->numberBetween(1000, 9999).' XX',
            'name' => $this->faker->randomElement(['HINO DUTRO', 'MITSUBISHI FUSO', 'ISUZU ELF']),
            'asset_type' => 'VEHICLE',
            'brand_model' => 'HINO',
            'site_id' => Site::factory(),
            'is_active' => true,
        ];
    }
}
