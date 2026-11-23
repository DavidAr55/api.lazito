<?php

namespace Database\Factories;

use App\Models\Exclusion;
use App\Models\Group;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exclusion>
 */
class ExclusionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
        ];
    }

    public function between(Participant $first, Participant $second): static
    {
        return $this->state(fn (): array => [
            'group_id' => $first->group_id,
            ...Exclusion::pairAttributes($first->id, $second->id),
        ]);
    }
}
