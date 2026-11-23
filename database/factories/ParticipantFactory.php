<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
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
            'name' => fake()->unique()->name(),
        ];
    }

    public function claimed(string $pin = '1234', ?string $claimToken = null): static
    {
        return $this->state(fn (): array => [
            'pin' => Hash::make($pin),
            'claim_token' => $claimToken ?? Str::random(40),
            'claimed_at' => now(),
        ]);
    }

    public function revealed(): static
    {
        return $this->claimed()->state(fn (): array => ['revealed_at' => now()]);
    }
}
