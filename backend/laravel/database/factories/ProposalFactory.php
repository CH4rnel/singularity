<?php

namespace Database\Factories;

use App\Models\Dao;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dao_id' => Dao::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'ends_at' => now()->addDays(7),
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['ends_at' => now()->addDays(7)]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['ends_at' => now()->subDay()]);
    }
}
