<?php

namespace Database\Factories;

use App\Models\ArenaComment;
use App\Models\ArenaSeason;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArenaComment> */
class ArenaCommentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['arena_season_id' => ArenaSeason::factory(), 'user_id' => User::factory(), 'body' => fake()->sentence(), 'parent_id' => null, 'hidden_at' => null];
    }
}
