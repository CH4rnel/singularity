<?php

namespace Database\Factories;

use App\Models\Proposal;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    protected $model = Reaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reactable_type' => Proposal::class,
            'reactable_id' => Proposal::factory(),
            'emoji' => fake()->randomElement(Reaction::PALETTE),
        ];
    }
}
