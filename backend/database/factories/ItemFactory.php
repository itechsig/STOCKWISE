<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('ITM.####')),
            'description' => strtoupper($this->faker->unique()->words(4, true)),
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'item_type' => 'CONSUMABLE',
            'needs_blueprint' => false,
            'lead_time_days' => $this->faker->numberBetween(3, 30),
            'is_active' => true,
            'source' => 'factory',
        ];
    }

    public function safetyStock(float $ss): static
    {
        return $this->afterCreating(function (Item $item) use ($ss) {
            $item->safetyStocks()->create([
                'safety_stock' => $ss,
                'is_effective' => true,
                'source_category' => 'factory',
            ]);
        });
    }

    public function withStock(float $actual, float $reserved = 0, ?int $warehouseId = null): static
    {
        return $this->afterCreating(function (Item $item) use ($actual, $reserved, $warehouseId) {
            $item->inventory()->create([
                'warehouse_id' => $warehouseId ?? Warehouse::factory()->create()->id,
                'actual_qty' => $actual,
                'reserved_qty' => $reserved,
                'stock_known' => true,
            ]);
        });
    }
}
