<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('U??')),
            'name' => $this->faker->word(),
            'is_active' => true,
        ];
    }
}
