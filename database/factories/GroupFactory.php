<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Intercambio '.fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'event_date' => fake()->dateTimeBetween('+1 week', '+3 months')->format('Y-m-d'),
            'budget' => fake()->randomElement([200, 300, 500]),
            'theme' => fake()->randomElement(['Pijamas', 'Calcetines navideños', 'Libros']),
            'theme_description' => null,
            'admin_token_hash' => hash('sha256', Str::random(40)),
        ];
    }

    public function withAdminToken(string $token): static
    {
        return $this->state(fn (): array => ['admin_token_hash' => hash('sha256', $token)]);
    }
}
