<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = ucwords($this->faker->unique()->words(2, true));

        return [
            'parent_id' => null,
            'name' => $name,
            'level' => 1,
            'path' => $name,
            'is_active' => true,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->id,
            'level' => $parent->level + 1,
            'path' => $parent->path.' > '.ucwords($this->faker->unique()->words(2, true)),
        ])->afterMaking(function (Category $c) use ($parent) {
            $c->path = $parent->path.' > '.$c->name;
        });
    }
}
