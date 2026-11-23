<?php

namespace Database\Factories;

use App\Models\ProductSearchLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSearchLog>
 */
class ProductSearchLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'serper',
            'query' => fake()->word(),
            'credits' => 2,
            'result_count' => fake()->numberBetween(0, 40),
        ];
    }
}
