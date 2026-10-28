<?php

namespace Database\Factories;

use App\Models\MaterialRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaterialRequest> */
class MaterialRequestFactory extends Factory
{
    protected $model = MaterialRequest::class;

    public function definition(): array
    {
        return [
            'number' => 'REQ/SDA/26/IX/'.$this->faker->unique()->numberBetween(1, 999),
            'requester_id' => User::factory(),
            'department_id' => null,
            'site_id' => Site::factory(),
            'purpose' => $this->faker->sentence(3),
            'work_location' => $this->faker->city(),
            'status' => 'DRAFT',
            'created_by' => fn (array $a) => $a['requester_id'],
        ];
    }
}
