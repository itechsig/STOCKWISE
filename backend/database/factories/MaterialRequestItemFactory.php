<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialRequestItem> */
class MaterialRequestItemFactory extends Factory
{
    protected $model = MaterialRequestItem::class;

    public function definition(): array
    {
        return [
            'material_request_id' => MaterialRequest::factory(),
            'item_id' => Item::factory(),
            'description_raw' => $this->faker->words(3, true),
            'qty_requested' => $this->faker->numberBetween(1, 20),
            'line_status' => 'PENDING',
            'physical_check_status' => 'NOT_CHECKED',
        ];
    }
}
