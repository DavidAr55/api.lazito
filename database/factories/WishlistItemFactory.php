<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'title' => fake()->words(3, true),
            'price' => fake()->numberBetween(50, 300),
        ];
    }
}
