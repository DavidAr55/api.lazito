<?php

namespace Database\Factories;

use App\Models\FixedAssignment;
use App\Models\Group;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedAssignment>
 */
class FixedAssignmentFactory extends Factory
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

    public function between(Participant $giver, Participant $receiver): static
    {
        return $this->state(fn (): array => [
            'group_id' => $giver->group_id,
            'giver_id' => $giver->id,
            'receiver_id' => $receiver->id,
        ]);
    }
}
